<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;
final class Database {
    private static ?PDO $pdo=null;
    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;
        $dsn=getenv('SALESYNC_DSN') ?: 'sqlite:' . __DIR__ . '/../seller.sqlite';
        self::$pdo=new PDO($dsn,getenv('SALESYNC_DB_USER')?:null,getenv('SALESYNC_DB_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        self::$pdo->exec('PRAGMA foreign_keys = ON'); self::install(self::$pdo); return self::$pdo;
    }
    private static function install(PDO $p): void {
        $p->exec("CREATE TABLE IF NOT EXISTS sellers(id INTEGER PRIMARY KEY, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS stores(id INTEGER PRIMARY KEY, seller_id INTEGER NOT NULL UNIQUE REFERENCES sellers(id), name TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', logo TEXT, banner TEXT, contact_number TEXT, email TEXT, address TEXT, status TEXT NOT NULL DEFAULT 'Active', rating REAL NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT);
CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY AUTOINCREMENT, seller_id INTEGER REFERENCES sellers(id), store_id INTEGER NOT NULL REFERENCES stores(id), name TEXT NOT NULL, sku TEXT NOT NULL, category TEXT, brand TEXT, description TEXT, price REAL NOT NULL CHECK(price>=0), discount_price REAL, stock INTEGER NOT NULL DEFAULT 0 CHECK(stock>=0), low_stock_threshold INTEGER NOT NULL DEFAULT 5 CHECK(low_stock_threshold>=0), reserved INTEGER NOT NULL DEFAULT 0 CHECK(reserved>=0), published INTEGER NOT NULL DEFAULT 0, archived INTEGER NOT NULL DEFAULT 0, hidden INTEGER NOT NULL DEFAULT 0, thumbnail TEXT, gallery_images TEXT NOT NULL DEFAULT '[]', specifications TEXT NOT NULL DEFAULT '{}', variants TEXT NOT NULL DEFAULT '[]', store_category_id TEXT, images TEXT NOT NULL DEFAULT '[]', pos_ref TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(store_id,sku));
CREATE TABLE IF NOT EXISTS product_requests(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), name TEXT NOT NULL, category TEXT, details TEXT, status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Rejected','Cancelled')), note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, status TEXT NOT NULL DEFAULT 'To Process', recipient_name TEXT, recipient_phone TEXT, address TEXT, total REAL NOT NULL DEFAULT 0, paid INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS order_items(id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id), product_id INTEGER NOT NULL REFERENCES products(id), quantity INTEGER NOT NULL CHECK(quantity>0), unit_price REAL NOT NULL, store_id INTEGER NOT NULL REFERENCES stores(id));
CREATE TABLE IF NOT EXISTS shipments(id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id), store_id INTEGER NOT NULL REFERENCES stores(id), status TEXT NOT NULL DEFAULT 'To Process', tracking TEXT, handed_over_at TEXT, UNIQUE(order_id,store_id));
CREATE TABLE IF NOT EXISTS returns(id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id), product_id INTEGER NOT NULL REFERENCES products(id), store_id INTEGER NOT NULL REFERENCES stores(id), quantity INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'Requested', reason TEXT, notes TEXT, seller_notes TEXT, solution TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS return_inspections(id INTEGER PRIMARY KEY AUTOINCREMENT, return_id INTEGER NOT NULL UNIQUE REFERENCES returns(id) ON DELETE CASCADE, outcome TEXT, notes TEXT NOT NULL DEFAULT '', received_at TEXT, inspected_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT);
CREATE TABLE IF NOT EXISTS return_history(id INTEGER PRIMARY KEY AUTOINCREMENT, return_id INTEGER NOT NULL REFERENCES returns(id) ON DELETE CASCADE, status TEXT NOT NULL, notes TEXT, actor_seller_id INTEGER REFERENCES sellers(id), created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS return_evidence(id INTEGER PRIMARY KEY AUTOINCREMENT, return_id INTEGER NOT NULL REFERENCES returns(id) ON DELETE CASCADE, store_id INTEGER NOT NULL REFERENCES stores(id), seller_id INTEGER NOT NULL REFERENCES sellers(id), path TEXT NOT NULL, mime_type TEXT NOT NULL, uploaded_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS refunds(id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id), store_id INTEGER NOT NULL REFERENCES stores(id), amount REAL NOT NULL CHECK(amount>=0), reason TEXT, status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Rejected')), created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT);
CREATE TABLE IF NOT EXISTS vouchers(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), name TEXT NOT NULL DEFAULT '', code TEXT NOT NULL, scope TEXT NOT NULL DEFAULT 'shop', discount_type TEXT NOT NULL CHECK(discount_type IN ('fixed','percent')), discount REAL NOT NULL CHECK(discount>0), max_discount REAL, min_spend REAL NOT NULL DEFAULT 0, usage_limit INTEGER NOT NULL DEFAULT 100, starts_at TEXT, ends_at TEXT, enabled INTEGER NOT NULL DEFAULT 1, usage_count INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(store_id,code));
CREATE TABLE IF NOT EXISTS flash_deals(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), product_id INTEGER NOT NULL REFERENCES products(id), price REAL NOT NULL, original_price REAL, status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Rejected','Cancelled')), management_note TEXT, starts_at TEXT, ends_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS conversations(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), customer_id INTEGER NOT NULL, customer_name TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT);
CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL REFERENCES conversations(id), sender_type TEXT NOT NULL, sender_id INTEGER NOT NULL, receiver_id INTEGER, body TEXT NOT NULL, is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), type TEXT NOT NULL, title TEXT NOT NULL, body TEXT, is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TRIGGER IF NOT EXISTS trg_return_requested_notification AFTER INSERT ON returns BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'return_requested','Return requested','Return request #'||NEW.id||' needs review.'); END;
CREATE TRIGGER IF NOT EXISTS trg_return_decision_notification AFTER UPDATE OF status ON returns WHEN OLD.status<>NEW.status AND NEW.status IN ('Management Approved','Refund Approved','Refund Denied','Refund Rejected','Refunded') BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,CASE WHEN NEW.status IN ('Refund Approved','Refunded') THEN 'refund_approved' WHEN NEW.status IN ('Refund Denied','Refund Rejected') THEN 'refund_rejected' ELSE 'management_decision' END,CASE WHEN NEW.status IN ('Refund Approved','Refunded') THEN 'Refund approved' WHEN NEW.status IN ('Refund Denied','Refund Rejected') THEN 'Refund rejected' ELSE 'Management decision recorded' END,'Return #'||NEW.id||' status: '||NEW.status||'.'); END;
CREATE TRIGGER IF NOT EXISTS trg_return_evidence_limit BEFORE INSERT ON return_evidence WHEN (SELECT COUNT(*) FROM return_evidence WHERE return_id=NEW.return_id)>=3 BEGIN SELECT RAISE(ABORT,'A return can have at most three evidence images'); END;
CREATE TRIGGER IF NOT EXISTS trg_flash_deal_management_notification AFTER UPDATE OF status ON flash_deals WHEN OLD.status<>NEW.status AND NEW.status IN ('Approved','Rejected') BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,CASE WHEN NEW.status='Approved' THEN 'flash_deal_approved' ELSE 'flash_deal_rejected' END,CASE WHEN NEW.status='Approved' THEN 'Flash deal approved' ELSE 'Flash deal rejected' END,'Flash deal request #'||NEW.id||' was '||lower(NEW.status)||'.'); END;
CREATE TABLE IF NOT EXISTS payouts(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), order_id INTEGER, gross REAL NOT NULL DEFAULT 0, commission REAL NOT NULL DEFAULT 0, seller_discount REAL NOT NULL DEFAULT 0, refund_adjust REAL NOT NULL DEFAULT 0, amount REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'Pending', created_at TEXT DEFAULT CURRENT_TIMESTAMP, released_at TEXT);
CREATE TABLE IF NOT EXISTS payout_history(id INTEGER PRIMARY KEY AUTOINCREMENT, payout_id INTEGER NOT NULL REFERENCES payouts(id) ON DELETE CASCADE, status TEXT NOT NULL, note TEXT, changed_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS financial_transactions(id INTEGER PRIMARY KEY AUTOINCREMENT, payout_id INTEGER NOT NULL REFERENCES payouts(id) ON DELETE CASCADE, store_id INTEGER NOT NULL REFERENCES stores(id), order_id INTEGER, type TEXT NOT NULL, amount REAL NOT NULL, description TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS stock_allocations(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), product_id INTEGER NOT NULL REFERENCES products(id), quantity INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'Pending', note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
        $p->exec("CREATE TABLE IF NOT EXISTS product_comparisons(id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL REFERENCES stores(id), product_id INTEGER NOT NULL REFERENCES products(id), visitor_key TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(product_id,visitor_key));");
        $p->exec("CREATE TABLE IF NOT EXISTS reviews(id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL REFERENCES products(id), store_id INTEGER NOT NULL REFERENCES stores(id), customer_id INTEGER, order_id INTEGER, rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5), review_text TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Pending' CHECK(status IN ('Pending','Approved','Published','Rejected')), seller_reply TEXT, reply_date TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(order_id,product_id,customer_id));
CREATE INDEX IF NOT EXISTS idx_reviews_store_status_created ON reviews(store_id,status,created_at);
CREATE INDEX IF NOT EXISTS idx_reviews_product_status ON reviews(product_id,status);
CREATE TRIGGER IF NOT EXISTS trg_review_received_insert AFTER INSERT ON reviews WHEN NEW.status IN ('Approved','Published') BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'new_review_received','New Review Received','A customer left a new review for product #'||NEW.product_id||'.'); END;
CREATE TRIGGER IF NOT EXISTS trg_review_received_publish AFTER UPDATE OF status ON reviews WHEN OLD.status NOT IN ('Approved','Published') AND NEW.status IN ('Approved','Published') BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'new_review_received','New Review Received','A customer review for product #'||NEW.product_id||' is now published.'); END;");
        self::ensureColumn($p,'products','seller_id','INTEGER REFERENCES sellers(id)');
        self::ensureColumn($p,'products','brand','TEXT');
        self::ensureColumn($p,'products','discount_price','REAL');
        self::ensureColumn($p,'products','low_stock_threshold','INTEGER NOT NULL DEFAULT 5');
        self::ensureColumn($p,'products','thumbnail','TEXT');
        self::ensureColumn($p,'products','gallery_images',"TEXT NOT NULL DEFAULT '[]'");
        self::ensureColumn($p,'products','specifications',"TEXT NOT NULL DEFAULT '{}'");
        self::ensureColumn($p,'products','variants',"TEXT NOT NULL DEFAULT '[]'");
        self::ensureColumn($p,'products','store_category_id','TEXT');
        self::ensureColumn($p,'products','updated_at','TEXT');
        $p->exec('UPDATE products SET seller_id=(SELECT seller_id FROM stores WHERE stores.id=products.store_id) WHERE seller_id IS NULL; UPDATE products SET updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP)');
        self::ensureColumn($p,'conversations','created_at','TEXT');
        self::ensureColumn($p,'conversations','updated_at','TEXT');
        self::ensureColumn($p,'conversations','customer_name','TEXT');
        self::ensureColumn($p,'messages','receiver_id','INTEGER');
        $p->exec("UPDATE conversations SET created_at=COALESCE(created_at,CURRENT_TIMESTAMP),updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_conversations_store_updated ON conversations(store_id,updated_at);
CREATE INDEX IF NOT EXISTS idx_messages_conversation_created ON messages(conversation_id,created_at);
CREATE TRIGGER IF NOT EXISTS trg_conversation_created_notification AFTER INSERT ON conversations BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'conversation_started','New Conversation Started','A customer started conversation #'||NEW.id||'.'); END;
CREATE TRIGGER IF NOT EXISTS trg_message_updates_conversation AFTER INSERT ON messages BEGIN UPDATE conversations SET updated_at=NEW.created_at WHERE id=NEW.conversation_id; END;
CREATE TRIGGER IF NOT EXISTS trg_customer_message_notification AFTER INSERT ON messages WHEN NEW.sender_type<>'seller' BEGIN INSERT INTO notifications(store_id,type,title,body) SELECT c.store_id,CASE WHEN (SELECT COUNT(*) FROM messages prior WHERE prior.conversation_id=NEW.conversation_id)>1 THEN 'customer_message_reply' ELSE 'customer_message_received' END,CASE WHEN (SELECT COUNT(*) FROM messages prior WHERE prior.conversation_id=NEW.conversation_id)>1 THEN 'Customer Replied' ELSE 'New Customer Message Received' END,'Unread message in conversation #'||c.id||'.' FROM conversations c WHERE c.id=NEW.conversation_id; END;
CREATE TRIGGER IF NOT EXISTS trg_customer_unread_reminder AFTER INSERT ON messages WHEN NEW.sender_type<>'seller' AND NOT EXISTS(SELECT 1 FROM messages prior WHERE prior.conversation_id=NEW.conversation_id AND prior.id<>NEW.id AND prior.sender_type<>'seller' AND prior.is_read=0) BEGIN INSERT INTO notifications(store_id,type,title,body) SELECT c.store_id,'unread_message_reminder','Unread Message Reminder','Conversation #'||c.id||' has an unread customer message.' FROM conversations c WHERE c.id=NEW.conversation_id; END;");
        self::ensureColumn($p,'returns','seller_notes','TEXT');
        self::ensureColumn($p,'stores','banner','TEXT');
        self::ensureColumn($p,'stores','contact_number','TEXT');
        self::ensureColumn($p,'stores','email','TEXT');
        self::ensureColumn($p,'stores','address','TEXT');
        self::ensureColumn($p,'stores','created_at','TEXT');
        $p->exec('UPDATE stores SET created_at=COALESCE(created_at,updated_at,CURRENT_TIMESTAMP)');
        self::ensureColumn($p,'returns','solution','TEXT');
        self::ensureColumn($p,'vouchers','name',"TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($p,'vouchers','scope',"TEXT NOT NULL DEFAULT 'shop'");
        self::ensureColumn($p,'vouchers','max_discount','REAL');
        self::ensureColumn($p,'vouchers','usage_limit','INTEGER NOT NULL DEFAULT 100');
        self::ensureColumn($p,'vouchers','created_at','TEXT');
        self::ensureColumn($p,'flash_deals','original_price','REAL');
        self::ensureColumn($p,'flash_deals','management_note','TEXT');
        self::ensureColumn($p,'flash_deals','created_at','TEXT');
        self::ensureColumn($p,'payouts','seller_discount','REAL NOT NULL DEFAULT 0');
        self::ensureColumn($p,'payouts','released_at','TEXT');
        $p->exec("UPDATE payouts SET released_at=COALESCE(released_at,created_at,CURRENT_TIMESTAMP) WHERE status IN ('Paid','Released');");
        $p->exec("UPDATE vouchers SET created_at=COALESCE(created_at,CURRENT_TIMESTAMP), starts_at=CASE WHEN length(starts_at)=10 THEN starts_at||' 00:00:00' ELSE starts_at END, ends_at=CASE WHEN length(ends_at)=10 THEN ends_at||' 23:59:59' ELSE ends_at END; UPDATE flash_deals SET created_at=COALESCE(created_at,CURRENT_TIMESTAMP), original_price=COALESCE(original_price,(SELECT price FROM products WHERE products.id=flash_deals.product_id));");
        $p->exec("INSERT INTO payout_history(payout_id,status,note,changed_at) SELECT p.id,p.status,'Existing payout record',COALESCE(p.created_at,CURRENT_TIMESTAMP) FROM payouts p WHERE NOT EXISTS(SELECT 1 FROM payout_history h WHERE h.payout_id=p.id); INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description,created_at) SELECT p.id,p.store_id,p.order_id,'gross_sales',p.gross,'Gross eligible sales',COALESCE(p.created_at,CURRENT_TIMESTAMP) FROM payouts p WHERE NOT EXISTS(SELECT 1 FROM financial_transactions t WHERE t.payout_id=p.id); INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description,created_at) SELECT p.id,p.store_id,p.order_id,'commission',-ABS(p.commission),'Marketplace commission',COALESCE(p.created_at,CURRENT_TIMESTAMP) FROM payouts p WHERE NOT EXISTS(SELECT 1 FROM financial_transactions t WHERE t.payout_id=p.id AND t.type='commission'); INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description,created_at) SELECT p.id,p.store_id,p.order_id,'seller_discount',-ABS(p.seller_discount),'Seller-funded discount',COALESCE(p.created_at,CURRENT_TIMESTAMP) FROM payouts p WHERE NOT EXISTS(SELECT 1 FROM financial_transactions t WHERE t.payout_id=p.id AND t.type='seller_discount'); INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description,created_at) SELECT p.id,p.store_id,p.order_id,'refund_adjustment',-ABS(p.refund_adjust),'Refund adjustment',COALESCE(p.created_at,CURRENT_TIMESTAMP) FROM payouts p WHERE NOT EXISTS(SELECT 1 FROM financial_transactions t WHERE t.payout_id=p.id AND t.type='refund_adjustment');");
        self::installPayoutTriggers($p);
    }
    private static function installPayoutTriggers(PDO $p): void {
        $p->exec("CREATE TRIGGER IF NOT EXISTS trg_payout_created AFTER INSERT ON payouts BEGIN
 INSERT INTO payout_history(payout_id,status,note) VALUES(NEW.id,NEW.status,'Payout generated');
 INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'payout_generated','New payout generated','Payout #'||NEW.id||' was generated.');
 INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description) VALUES(NEW.id,NEW.store_id,NEW.order_id,'gross_sales',NEW.gross,'Gross eligible sales');
 INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description) VALUES(NEW.id,NEW.store_id,NEW.order_id,'commission',-ABS(NEW.commission),'Marketplace commission');
 INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description) VALUES(NEW.id,NEW.store_id,NEW.order_id,'seller_discount',-ABS(NEW.seller_discount),'Seller-funded discount');
 INSERT INTO financial_transactions(payout_id,store_id,order_id,type,amount,description) VALUES(NEW.id,NEW.store_id,NEW.order_id,'refund_adjustment',-ABS(NEW.refund_adjust),'Refund adjustment');
END;
CREATE TRIGGER IF NOT EXISTS trg_payout_history AFTER UPDATE OF status ON payouts WHEN OLD.status<>NEW.status BEGIN INSERT INTO payout_history(payout_id,status,note) VALUES(NEW.id,NEW.status,'Payout status changed'); END;
CREATE TRIGGER IF NOT EXISTS trg_payout_processing AFTER UPDATE OF status ON payouts WHEN OLD.status<>NEW.status AND NEW.status='Processing' BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'payout_processing','Payout processing','Payout #'||NEW.id||' is processing.'); END;
CREATE TRIGGER IF NOT EXISTS trg_payout_released AFTER UPDATE OF status ON payouts WHEN OLD.status<>NEW.status AND NEW.status IN ('Released','Paid') BEGIN UPDATE payouts SET released_at=COALESCE(NEW.released_at,CURRENT_TIMESTAMP) WHERE id=NEW.id; INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'payout_released','Payout released','Payout #'||NEW.id||' was released.'); END;
CREATE TRIGGER IF NOT EXISTS trg_payout_failed AFTER UPDATE OF status ON payouts WHEN OLD.status<>NEW.status AND NEW.status='Failed' BEGIN INSERT INTO notifications(store_id,type,title,body) VALUES(NEW.store_id,'payout_failed','Payout failed','Payout #'||NEW.id||' failed.'); END;");
    }
    private static function ensureColumn(PDO $p,string $table,string $column,string $type): void {
        $q=$p->query('PRAGMA table_info('.$table.')');$columns=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($columns as $row)if(($row['name']??'')===$column)return;
        $p->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$type);
    }
}
