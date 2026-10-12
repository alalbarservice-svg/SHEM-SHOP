// Fixed, explicitly authorized QA fixtures. Disabled without a temporary secret.
export async function qaFixtures(env, data, home) {
  const batch = 'qa-20261009';
  if (!['seed', 'sale', 'regular'].includes(data.action)) throw Object.assign(new Error('Invalid QA action'), {status:400});
  if (home && data.action !== 'seed') throw Object.assign(new Error('Edit source prices on SHEM'), {status:400});
  const stamp = new Date().toISOString(), statements = [];
  const add = (table,id,obj) => statements.push(env.DB.prepare(`INSERT INTO ${table}(id,data,updated_at) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at`).bind(id,JSON.stringify(obj),stamp));
  for (const [kind,price] of [['knit',200],['shirt',129.9]]) {
    const sourceId=`qa-shem-${kind}-20261009`, homeId=`qa-shimi-${kind}-20261009`, id=home?homeId:sourceId;
    const row=await env.DB.prepare('SELECT data FROM products WHERE id=?').bind(id).first();
    const existing=row?JSON.parse(row.data):null;
    if(existing && existing.qaBatch!==batch) throw Object.assign(new Error('QA fixture collision'),{status:409});
    if(data.action==='seed' && existing) continue;
    if(data.action!=='seed' && !existing) throw Object.assign(new Error('Seed QA fixtures first'),{status:409});
    const sku=kind==='knit'?'QA-OCT09-KNIT':'QA-OCT09-SHIRT';
    const variants=['M','L'].map(size=>({id:`${id}-${size}`,size,color:home?'אבן':'שחור',sku:`${sku}-${size}`,homeSku:`${sku}-${size}`,stock:10}));
    const p=existing || {id,he:`בדיקת QA - ${kind==='knit'?'סריג':'חולצה'} ${home?'SHIMI':'SHEM'}`,en:`QA test ${kind} ${home?'SHIMI':'SHEM'}`,brand:home?'SHIMI':'SHEM',cat:'clothing',sku,price,stock:20,sizeStock:{M:10,L:10},sizes:['M','L'],color:home?'אבן':'שחור',imageUrl:`/assets/${kind}.png`,image:kind,active:true,demo:true,qaBatch:batch,variants,description:'פריט בדיקה בלבד עם מלאי בדיקה, אינו מלאי המחסן. ניתן למחוק לאחר הבדיקות.'};
    if(!home){p.homeSku=sku;p.homeProductId=homeId; p.price=data.action==='sale'&&kind==='knit'?179:price;if(data.action==='sale'&&kind==='knit')p.previousPrice=219;else delete p.previousPrice;}
    add('products',id,p);
    if(data.action==='seed') add('product_links',id,home?{id,sourceId,sourceUrl:env.SHEM_ORIGIN+'/api/channel/source/'+sourceId,variantMap:Object.fromEntries(['M','L'].map(s=>[`${homeId}-${s}`,`${sourceId}-${s}`])),status:'pending',qaBatch:batch,createdAt:stamp}:{id,homeId,variantMap:Object.fromEntries(['M','L'].map(s=>[`${homeId}-${s}`,`${sourceId}-${s}`])),status:'connected',qaBatch:batch,createdAt:stamp});
  }
  if(!home && data.action==='seed') {
    const id='qa-coupon-20261009', row=await env.DB.prepare('SELECT data FROM promotions WHERE id=?').bind(id).first();
    if(row && JSON.parse(row.data).qaBatch!==batch) throw Object.assign(new Error('QA coupon collision'),{status:409});
    if(!row)add('promotions',id,{id,name:'קופון בדיקת QA',code:'QAOCT09',discount:10,active:true,qaBatch:batch,minSubtotal:0,excludeSale:false});
  }
  statements.push(env.DB.prepare('INSERT INTO audit(id,actor,action,created_at) VALUES(?,?,?,?)').bind(crypto.randomUUID(),'system:user-authorized-qa',`QA fixtures ${data.action} ${home?'SHIMI':'SHEM'}`,stamp));
  await env.DB.batch(statements);
  return {ok:true,action:data.action,qaBatch:batch,products:2};
}
