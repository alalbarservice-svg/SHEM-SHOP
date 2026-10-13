export async function adminApi(path,options={}) {
 const response=await fetch(`/api/admin/${path}`,{...options,headers:{...(options.body?{'content-type':'application/json'}:{}),...options.headers}});
 let data;try{data=await response.json()}catch{throw new Error('השרת אינו זמין. יש לנסות שוב.')}
 if(!response.ok)throw Object.assign(new Error(data.error||'הפעולה נכשלה.'),{status:response.status});return data;
}
