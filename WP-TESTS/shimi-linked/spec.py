# 48 SHIMI items, one per real SHEM item (SHEM price 1.00 = real item, per owner 25.10.2026).
# SKU and price are NOT set here: they come from the SHEM source through the channel link.
# Color and sizes must equal the linked SHEM item (variant mapping is one-to-one).
COL = {  # he: (en, prompt phrase, name fragment)
 'שחור':('black','black','שחור'),'שנהב':('ivory','ivory','שנהב'),'לבן':('white','white','לבן'),
 'נייבי':('navy','navy blue','נייבי'),'כחול נייבי':('navy','navy blue','נייבי'),'תכלת':('light blue','light blue','תכלת'),
 'אינדיגו':('indigo','indigo blue','אינדיגו'),'טאופ':('taupe','taupe','טאופ'),'חול':('sand','sand beige','חול'),
 'אפור':('grey','heather grey','אפור'),'אפור מרווה':('sage grey','sage grey-green','אפור מרווה'),
 'פחם':('charcoal','charcoal grey','פחם'),'שיבולת שועל':('oatmeal','oatmeal','שיבולת שועל'),
 'נייבי ושנהב':('navy and ivory','two-tone navy blue and ivory','נייבי ושנהב'),
 'שנהב וטאופ':('ivory and taupe','two-tone ivory and taupe','שנהב וטאופ'),
 "בז'":('beige','beige',"בז'"),'זית':('olive','olive green','זית'),'אבן':('stone','stone beige','אבן'),
 'אפור כסוף':('silver grey','silver grey','אפור כסוף'),'שנהב וירוק':('ivory and green','ivory with green accents','שנהב וירוק'),
}
KIND = {  # kind: (he name, en name, prompt object)
 'tee':('חולצת טי','Tee','a crew-neck short-sleeve cotton t-shirt'),
 'ls':('חולצת לונג סליב','Long sleeve top','a crew-neck long-sleeve cotton top'),
 'bd':('חולצה מכופתרת','Button-up shirt','a long-sleeve button-up cotton shirt'),
 'knit':('סריג צמר','Wool knit','a fine-knit crew-neck wool sweater'),
 'loafer':('נעלי לואפר עור','Leather loafers','a pair of leather penny loafers'),
 'sneaker':('סניקרס עור מינימליסטיות','Minimal leather sneakers','a pair of minimal low-top leather sneakers'),
 'trousers':('מכנסיים בגזרה ישרה','Straight trousers','straight-leg tailored trousers'),
}
SIZES = {'tee':'S M L XL','ls':'S M L XL','bd':'S M L XL','knit':'S M L XL','trousers':'30 32 34 36',
         'loafer':'40 41 42 43 44 45','sneaker':'40 41 42 43 44 45'}
SHEM = [  # (shem sku, kind, shem color he)
('LP-TR-01','trousers','נייבי'),('LP-LT-03','ls','אפור מרווה'),('LP-LT-02','ls','טאופ'),('LP-LT-01','ls','פחם'),
('LP-BD-07','bd','לבן'),('LP-BD-06','bd','שנהב'),('LP-BD-05','bd','תכלת'),('LP-BD-04','bd','תכלת'),('LP-BD-03','bd','אינדיגו'),
('LP-BD-02','bd','אינדיגו'),('LP-BD-01','bd','שיבולת שועל'),('LP-TEE-05','tee','שחור'),('LP-TEE-04','tee','נייבי ושנהב'),
('LP-TEE-03','tee','שחור'),('LP-TEE-02','tee','שנהב וטאופ'),('LP-TEE-01','tee','שנהב'),
('LP-SHO-10','loafer',"בז'"),('LP-SHO-09','loafer','נייבי'),('LP-SHO-08','loafer','חול'),('LP-SHO-07','loafer','שחור'),
('LP-SHO-06','loafer','זית'),('LP-SHO-05','loafer','אבן'),('LP-SHO-04','loafer','אפור כסוף'),('LP-SHO-03','loafer','טאופ'),
('LP-SHO-02','loafer','שחור'),('LP-SHO-01','loafer','שחור'),
('RL-KN-07','knit','נייבי'),('RL-KN-06','knit','שנהב'),('RL-KN-05','knit','חול'),('RL-KN-04','knit','נייבי'),
('RL-KN-03','knit','אפור'),('RL-KN-02','knit','שחור'),('RL-KN-01','knit','שנהב'),
('RL-BD-04','bd','שחור'),('RL-BD-03','bd','אינדיגו'),('RL-BD-02','bd','תכלת'),('RL-BD-01','bd','לבן'),
('RL-TEE-03','tee','כחול נייבי'),('RL-TEE-02','tee','שחור'),('RL-TEE-01','tee','לבן'),
('AMIRI-SHO-05','sneaker','אפור'),('AMIRI-SHO-04','sneaker','שחור'),('AMIRI-SHO-03','sneaker','שנהב וירוק'),
('AMIRI-SHO-02','sneaker','שנהב'),('AMIRI-SHO-01','sneaker','שנהב'),
('AMIRI-TEE-03','tee','לבן'),('AMIRI-TEE-02','tee','שחור'),('AMIRI-TEE-01','tee','לבן'),
]
# differentiators so two items of the same kind and color are not identical in name/photo
DIFF = {'tee':['','אוברסייז','צווארון V','פולו פיקה','משקל כבד'],'ls':['','צווארון V'],'bd':['אוקספורד','פופלין','פשתן','צווארון מנדרין','מחויטת'],
        'knit':['','צווארון V','רוכסן חצי'],'loafer':['','עם רצועה','סוליית גומי'],'sneaker':['','עם סוליה עבה','גבוהות'],'trousers':['']}
DIFF_EN = {'אוברסייז':'oversized','צווארון V':'V-neck','פולו פיקה':'pique polo','רב-שכבתי':'layered','אוקספורד':'oxford','פופלין':'poplin',
           'פשתן':'linen','צווארון מנדרין':'mandarin collar','צווארון עגול':'crew neck','רוכסן חצי':'half-zip','עם רצועה':'with strap',
           'סוליית גומי':'rubber sole','עם סוליה עבה':'chunky sole','משקל כבד':'heavyweight','מחויטת':'tailored','גבוהות':'high-top'}
def items():
    seen={}
    out=[]
    for i,(sku,kind,col) in enumerate(SHEM):
        k=(kind,col); n=seen.get(k,0); seen[k]=n+1
        pool=DIFF[kind]; d=pool[n%len(pool)] if kind!='bd' else pool[(sum(1 for o in out if o['kind']=='bd')+n)%len(pool)]
        he_n,en_n,obj=KIND[kind]; en_c,pc,hc=COL[col]
        he=(f"{he_n} {d}".replace('  ',' ').strip()+f" - {hc}"); en=f"{en_c.title()} {DIFF_EN.get(d,'')} {en_n.lower()}".replace('  ',' ').strip()
        desc=obj if not d else obj+f", {DIFF_EN[d]} style"
        prompt=f"Studio product photo of {desc}, color {pc}, single item centered, soft natural shadow, warm beige seamless background, premium minimal basics fashion catalog style, no text, no logo, no person, 4:3 landscape"
        out.append(dict(i=i,shem_sku=sku,kind=kind,color=col,he=he,en=en,sizes=SIZES[kind].split(),prompt=prompt))
    return out
if __name__=='__main__':
    its=items(); print(len(its)); names=[x['he'] for x in its]; print(len(set(names)),'unique names')
    for x in its[:6]: print(x['he'],'|',x['en'])
