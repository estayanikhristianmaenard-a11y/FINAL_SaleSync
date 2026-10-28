// Optional adversarial audit. Records defects without turning them into passing acceptance tests.
import fs from 'node:fs/promises';
import path from 'node:path';

export async function auditCustomer({evaluate,fixture,route,click,wait,directory}) {
    const findings=[];
    const record=(id,expected,actual,passed)=>{
        findings.push({id,expected,actual,passed});
        console.log(`${passed===null?'OBSERVE':passed?'PASS':'FAIL'} AUDIT ${id}: ${JSON.stringify(actual)}`);
    };
    const moduleUrl="new URL('assets/js/customer/customer-data.js',location.href).href";
    const apiUrl="new URL('assets/js/customer/api.js',location.href).href";

    // More than one page of history, with an unread seller message outside the newest page.
    fixture(`$t=$db->one('SELECT t.thread_id,t.customer_id,s.user_id AS seller_user_id FROM customer_threads t JOIN seller_profiles s USING(seller_id) LIMIT 1');
        $db->run("UPDATE customer_messages SET read_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')");
        $db->run("INSERT INTO customer_messages(thread_id,sender_id,body,client_key) VALUES (?,?,'Older unread seller reply','audit-older-unread')",[$t['thread_id'],$t['seller_user_id']]);
        for($i=0;$i<60;$i++) $db->run("INSERT INTO customer_messages(thread_id,sender_id,body,client_key,created_at) VALUES (?,?,?,?,'2020-01-01T00:00:00Z')",[$t['thread_id'],$t['customer_id'],'History message '.$i,'audit-history-'.$i]);`);
    await evaluate('S.chats=[]');
    await route('home');await route('account');
    const unread=await evaluate(`(async()=>{const {api}=await import(${apiUrl});const rows=await api('/conversations');return {server:rows.reduce((n,t)=>n+t.unread,0),client:unreadMessages(),hasOlder:S.chats[0].hasOlder,loadedUnread:S.chats[0].messages.filter(m=>m.by!==userId&&!m.read).length}})()`);
    record('unread-older-than-first-page','Client unread count equals server count even with the unread reply outside the loaded page',unread,unread.client===unread.server&&unread.server===1&&unread.hasOlder&&unread.loadedUnread===0);

    await route('messages');
    await click('[data-action="chat-older"]');
    const older=await evaluate(`(async()=>{const before=S.chats[0].messages.length;const {loadConversations}=await import(${moduleUrl});await loadConversations();render();return {before,after:S.chats[0].messages.length}})()`);
    record('history-refresh-preserves-loaded-pages','Refreshing conversations retains previously loaded older messages',older,older.after>=older.before);

    await evaluate(`window.auditOldChatForm=document.querySelector('#chat-form');document.querySelector('#chat-form [name=text]').value='Unsent customer question';document.dispatchEvent(new Event('visibilitychange'))`);
    await wait('document.querySelector("#chat-form")!==window.auditOldChatForm');
    const draft=await evaluate('document.querySelector("#chat-form [name=text]")?.value');
    record('draft-survives-tab-return','Unsent customer question',draft,draft==='Unsent customer question');
    await route('account');await route('messages');
    const restoredDraft=await evaluate('document.querySelector("#chat-form [name=text]")?.value');
    record('draft-survives-navigation','Unsent customer question',restoredDraft,restoredDraft==='Unsent customer question');

    // Observe request fan-out on an otherwise ordinary account refresh.
    const requests=await evaluate(`(async()=>{const original=window.fetch;let count=0;window.fetch=(...args)=>{count++;return original(...args)};try{const {loadPrivate}=await import(${moduleUrl});await loadPrivate();return {requests:count,orders:S.orders.length,threads:S.chats.length}}finally{window.fetch=original}})()`);
    record('account-fetch-fanout','Observation only; account refresh should not require all historical order/message details',requests,null);

    // Text sent through the API must remain text in the real message view.
    await evaluate(`(async()=>{const {api}=await import(${apiUrl});await api('/conversations/'+S.chats[0].id+'/messages',{method:'POST',body:{body:'<img src=x onerror="window.auditInjected=true">',client_key:crypto.randomUUID()}});const {loadConversations}=await import(${moduleUrl});await loadConversations();render()})()`);
    const xss=await evaluate('!window.auditInjected&&[...document.querySelectorAll(".bubble p")].some(p=>p.textContent.includes("<img src=x"))&&!document.querySelector(".bubble img")');
    record('message-html-escaping','Message HTML remains literal text without execution',xss,xss);

    // End the server session independently of the UI, as with expiration/revocation in another tab.
    fixture("$db->run('UPDATE users SET auth_version=auth_version+1 WHERE user_id=(SELECT customer_id FROM customer_threads LIMIT 1)');");
    await route('home');
    await evaluate('go("account")');
    await wait('Boolean(document.querySelector("#auth-form"))&&!getUser()');
    const expired=await evaluate(`(async()=>{const {api}=await import(${apiUrl});const s=await api('/session');return {serverAuthenticated:Boolean(s.user),clientAuthenticated:Boolean(getUser()),loginVisible:Boolean(document.querySelector('#auth-form')),profileVisible:Boolean(document.querySelector('.account-content')),privateRows:['users','orders','cart','chats','notifications','returns','applications'].reduce((sum,key)=>sum+S[key].length,0),drafts:Object.keys(view.chatDrafts||{}).length}})()`);
    record('expired-session-clears-cached-account','Signed-out server session clears client identity, private data and drafts and prompts sign-in',expired,!expired.clientAuthenticated&&expired.loginVisible&&!expired.profileVisible&&expired.privateRows===0&&expired.drafts===0);

    await fs.writeFile(path.join(directory,'adversarial-audit.json'),JSON.stringify({findings},null,2));
    console.log(`AUDIT: ${findings.filter(f=>f.passed===false).length} confirmed failures; ${findings.filter(f=>f.passed===true).length} passed control; ${findings.filter(f=>f.passed===null).length} observation.`);
    return findings;
}
