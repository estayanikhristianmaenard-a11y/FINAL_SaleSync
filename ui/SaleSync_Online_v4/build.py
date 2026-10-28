"""Owner: Core team | Build a deterministic standalone SaleSync HTML bundle and four portal pages. Python 3 stdlib."""
from pathlib import Path
import html
BASE=Path(__file__).resolve().parent
CORE_JS=['core/portal.js','core/catalog.js','core/product-photos.js','core/product-options.js','core/catalog-refresh.js','core/brands.js','core/product-vectors.js','core/storefront-model.js','core/seed.js','core/store.js','core/events.js','core/ledger.js','core/selectors.js','core/ui-kit.js','core/adapters.js','core/auth.js','core/actions.js','core/workflows.js','core/workspaces.js','core/workspace-features.js','core/router.js']
PORTAL_JS={
 'customer':['customer/shop/shop.js','customer/shop/storefront.js','customer/account/account.js','customer/shop/compare.js'],
 'seller':['seller/seller.js'],
 'management':['management/admin-api.js','management/management.js'],
 'maintenance':['maintenance/maintenance.js'],
}
CSS=['core/tokens.css','core/base.css']
PORTAL_CSS={k:[f'{k}/{k}.css'] for k in PORTAL_JS}
PORTAL_CSS['customer'].append('customer/storefront.css')
BODY='''<div id="app"></div><dialog id="dialog" class="modal"><div class="modal-title"><span id="modal-title"></span><button type="button" id="close-modal" aria-label="Close dialog">×</button></div><form id="modal-form"><div class="modal-fields" id="modal-fields"></div><p id="modal-error" class="modal-error" style="padding:0 19px"></p><div class="modal-actions"><button type="button" class="btn ghost" id="cancel-modal">Cancel</button><button type="submit" class="btn primary" id="modal-btn">Save</button></div></form></dialog><div id="toasts" class="toastbox" aria-live="polite"></div>'''
SWITCHER_CSS='''#bundle-portal-bar{display:flex;align-items:center;justify-content:center;gap:7px;padding:9px 15px;background:#111a39;color:#e8efff;font:12px system-ui,sans-serif;position:relative;z-index:70;flex-wrap:wrap}#bundle-portal-bar button{font:inherit;color:#dce4ff;background:#263257;border:1px solid #4b5581;padding:6px 10px;border-radius:8px;cursor:pointer}#bundle-portal-bar button.on{background:#f36f21;color:#161e37;border-color:#ffbe84;font-weight:800}'''

def build_one(portal,inline=False):
 all_portals=['customer','seller','management','maintenance'] if inline else [portal]
 css_paths=CSS+sum((PORTAL_CSS[p] for p in all_portals),[])+['core/workspace.css','core/experience.css']
 js_paths=CORE_JS+sum((PORTAL_JS[p] for p in all_portals),[])+['core/commerce-model.js','core/experience.js','core/experience-admin.js','core/experience-actions.js','core/bootstrap.js']
 title={'customer':'Shop & My Account','seller':'Seller Centre','management':'Management Console','maintenance':'Maintenance Operations'}
 out=['<!doctype html>','<html lang="en"><head>','<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',f'<title>SaleSync Online | {"All Portals" if inline else title[portal]}</title>','<meta name="theme-color" content="#0f146d">']
 if inline:
  out.append('<style>'+SWITCHER_CSS+'\n'+'\n'.join((BASE/p).read_text(encoding='utf-8') for p in css_paths).replace('</style>','<\\/style>')+'</style>')
 else:
  for p in css_paths:out.append(f'<link rel="stylesheet" href="{p}">')
 out.extend(['</head><body>','<nav id="bundle-portal-bar" aria-label="Portals"></nav>' if inline else '',BODY])
 config=f'window.SaleSyncConfig={{portal:"{portal if not inline else "customer"}",bundle:{str(inline).lower()}}};'
 out.append('<script>'+config+'</script>')
 if inline:
  for p in js_paths:
   js=(BASE/p).read_text(encoding='utf-8').replace('</script>','<\\/script>')
   if p in ['core/catalog.js','core/product-photos.js','core/product-options.js']:
    import base64
    for photo in sorted((BASE/'assets/products').glob('*.webp')):
     js=js.replace('assets/products/'+photo.name,'data:image/webp;base64,'+base64.b64encode(photo.read_bytes()).decode())
   out.append('\n<script data-source="'+p+'">\n'+js+'\n</script>')
 else:
  for p in js_paths:out.append(f'<script src="{p}"></script>')
 out.append('</body></html>')
 name='SaleSync_Online_ALL.html' if inline else portal+'.html'
 (BASE/name).write_text('\n'.join(out)+'\n',encoding='utf-8')
 return name

if __name__=='__main__':
 for p in PORTAL_JS:print('Built',build_one(p))
 print('Built',build_one('customer',True))
