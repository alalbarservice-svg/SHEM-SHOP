import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {Miniflare} from 'miniflare';
import {documentRegistrationNumber,documentHTML} from '../worker/documents.js';
test('registration migration changes only invoice identity and preserves invoice-only document scope',async()=>{
 const mf=new Miniflare({modules:true,script:'export default {fetch(){return new Response("isolated")}}',compatibilityDate:'2026-08-06',d1Databases:['DB']});
 try {
  const db=await mf.getD1Database('DB');await db.prepare('CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT NOT NULL)').run();
  await db.prepare('INSERT INTO settings VALUES(?,?,?)').bind('business',JSON.stringify({registrationNumber:'BUSINESS-UNCHANGED',legalName:'Unchanged'}),'before').run();
  await db.prepare('INSERT INTO settings VALUES(?,?,?)').bind('invoice',JSON.stringify({legalName:'Existing business',address:'Existing address',registrationNumber:'old'}),'before').run();
  await db.prepare(readFileSync('drizzle/0005_invoice_registration_number.sql','utf8')).run();
  const invoice=JSON.parse((await db.prepare("SELECT value FROM settings WHERE key='invoice'").first()).value);
  const business=JSON.parse((await db.prepare("SELECT value FROM settings WHERE key='business'").first()).value);
  assert.equal(invoice.registrationNumber,'516067873');assert.equal(invoice.legalName,'Existing business');assert.equal(invoice.address,'Existing address');assert.equal(business.registrationNumber,'BUSINESS-UNCHANGED');
  const doc={business:invoice,customer:{},items:[],issuedAt:new Date().toISOString(),subtotalCents:0,totalCents:0};
  assert.equal(documentRegistrationNumber(doc,'invoice'),'516067873');assert.equal(documentRegistrationNumber(doc,'order'),'');assert.ok(documentHTML(doc).includes('516067873'));
 }finally{await mf.dispose();}
});
