import test from "node:test";
import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {createRequire} from "node:module";
import {Miniflare} from "miniflare";
import {build} from "esbuild";
import {createElement} from "react";
import {renderToStaticMarkup} from "react-dom/server";
import {api} from "../worker/api.js";
test("terms show only this store identity, retain leading zeros, and do not modify invoice settings", async()=>{
 const mf=new Miniflare({modules:true,script:'export default {fetch(){return new Response("isolated")}}',compatibilityDate:"2026-08-06",d1Databases:["DB"]});
 try {
  const DB=await mf.getD1Database("DB");await DB.prepare("CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT NOT NULL)").run();
  const original={legalName:"Existing seller",address:"Existing address",phone:"0500000000",registrationNumber:"OLD"};
  const invoice={legalName:"Separate invoice",registrationNumber:"INVOICE-UNCHANGED"};
  await DB.prepare("INSERT INTO settings VALUES(?,?,?)").bind("business",JSON.stringify(original),"before").run();
  await DB.prepare("INSERT INTO settings VALUES(?,?,?)").bind("invoice",JSON.stringify(invoice),"before").run();
  await DB.prepare(readFileSync("drizzle/0005_terms_registration_number.sql","utf8")).run();
  const response=await api(new Request("https://shop.test/api/store"),{DB});assert.equal(response.status,200);
  const data=await response.json();assert.equal(data.business.registrationNumber,"043644418");assert.equal(data.business.legalName,original.legalName);assert.equal(data.business.address,original.address);
  const saved=JSON.parse((await DB.prepare("SELECT value FROM settings WHERE key='invoice'").first()).value);assert.deepEqual(saved,invoice);
  const compiled=await build({entryPoints:["src/components/PolicyPages.jsx"],bundle:true,platform:"node",format:"cjs",write:false,jsx:"automatic",external:["react"]});
  const module={exports:{}};new Function("module","exports","require",compiled.outputFiles[0].text)(module,module.exports,createRequire(import.meta.url));
  const html=renderToStaticMarkup(createElement(module.exports.PolicyPages,{page:"terms",business:data.business}));
  assert.ok(html.includes("SHIMI"));assert.ok(html.includes('<bdi dir="ltr">043644418</bdi>'));assert.ok(!html.includes("516067873"));assert.ok(html.includes("13.10.2026"));
 }finally{await mf.dispose();}
});
