import type { Request, Response } from 'express';
import crypto from 'node:crypto';
import { env } from '../../shared/config/env.js';
import { pool } from '../../shared/db/pool.js';

export async function paymongoWebhook(req:Request,res:Response):Promise<void>{
  const secret=env.PAYMONGO_WEBHOOK_SECRET;
  const signature=req.header('paymongo-signature');
  if(!secret||!signature||!Buffer.isBuffer(req.body)){res.status(401).json({error:{code:'INVALID_SIGNATURE',message:'Webhook signature is invalid'}});return;}
  const parts=Object.fromEntries(signature.split(',').map(p=>p.trim().split('=')));
  const timestamp=parts.t||'';
  const timestampSeconds=Number(timestamp);
  if(!Number.isFinite(timestampSeconds)||Math.abs(Date.now()/1000-timestampSeconds)>300){res.status(401).json({error:{code:'INVALID_SIGNATURE',message:'Webhook timestamp is invalid or expired'}});return;}
  const digest=crypto.createHmac('sha256',secret).update(timestamp+'.').update(req.body).digest('hex');
  const supplied=env.NODE_ENV==='production'?parts.li:parts.te;
  const expected=env.NODE_ENV==='production'?digest:digest;
  const a=Buffer.from(supplied||''),b=Buffer.from(expected);
  if(a.length!==b.length||!crypto.timingSafeEqual(a,b)){res.status(401).json({error:{code:'INVALID_SIGNATURE',message:'Webhook signature is invalid'}});return;}
  let payload:any;try{payload=JSON.parse(req.body.toString('utf8'));}catch{res.status(400).json({error:{code:'INVALID_PAYLOAD',message:'Malformed JSON'}});return;}
  const eventId=String(payload?.data?.id||''),eventType=String(payload?.data?.attributes?.type||''),attributes=payload?.data?.attributes?.data?.attributes||{};
  const reference=String(attributes.payment_intent_id||attributes.checkout_session_id||attributes.id||'');
  if(!eventId||!eventType||!reference){res.status(400).json({error:{code:'INVALID_EVENT',message:'Event id, type and payment reference are required'}});return;}
  const next= /paid|succeeded/i.test(eventType)?'paid':/failed|cancelled/i.test(eventType)?'failed':null;
  const c=await pool.connect();try{await c.query('BEGIN');const ins=await c.query(`INSERT INTO payment_events(payment_id,provider_event_id,event_type) SELECT id,$1,$2 FROM payments WHERE provider='paymongo' AND provider_reference=$3 ON CONFLICT(provider_event_id) DO NOTHING RETURNING payment_id`,[eventId,eventType,reference]);if(!ins.rowCount){const duplicate=await c.query(`SELECT 1 FROM payment_events WHERE provider_event_id=$1`,[eventId]);await c.query('ROLLBACK');if(duplicate.rowCount){res.status(200).json({received:true,deduplicated:true});return;}res.status(404).json({error:{code:'PAYMENT_NOT_FOUND',message:'Payment reference was not found'}});return;}if(next){const paymentId=ins.rows[0].payment_id;await c.query(`UPDATE payments SET status=$2,paid_at=CASE WHEN $2='paid' THEN now() ELSE paid_at END,updated_at=now() WHERE id=$1`,[paymentId,next]);await c.query(`UPDATE orders SET payment_status=$2,updated_at=now() WHERE id=(SELECT order_id FROM payments WHERE id=$1)`,[paymentId,next]);}await c.query(`UPDATE payment_events SET processed_at=now(),processing_status='processed' WHERE provider_event_id=$1`,[eventId]);await c.query('COMMIT');res.status(200).json({received:true});}catch{await c.query('ROLLBACK');res.status(500).json({error:{code:'WEBHOOK_PROCESSING_FAILED',message:'Event processing failed'}});}finally{c.release();}
}
