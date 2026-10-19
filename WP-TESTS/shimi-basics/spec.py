# (he, en, color_he, kind, price, prev_price, prompt_desc)
S="S M L XL".split(); P="30 32 34 36".split(); SH="40 41 42 43 44 45".split(); H=["אחיד"]
items=[
("חולצת טי כותנה לבנה","White cotton tee","לבן","shirt",79,0,"a plain white crew-neck cotton t-shirt"),
("חולצת טי כותנה שחורה","Black cotton tee","שחור","shirt",79,0,"a plain black crew-neck cotton t-shirt"),
("חולצת טי אפור מלאנז'","Heather grey tee","אפור","shirt",79,0,"a plain heather grey crew-neck cotton t-shirt"),
("חולצת טי צווארון V נייבי","Navy V-neck tee","נייבי","shirt",85,0,"a plain navy blue V-neck cotton t-shirt"),
("חולצת פולו פיקה אבן","Stone pique polo","אבן","shirt",119,139,"a stone beige cotton pique polo shirt with a small collar"),
("חולצה מכופתרת אוקספורד לבנה","White oxford shirt","לבן","shirt",139,0,"a white long-sleeve button-up oxford shirt"),
("חולצה מכופתרת כחול בהיר","Light blue shirt","תכלת","shirt",139,0,"a light blue long-sleeve button-up cotton shirt"),
("חולצת לונג סליב שחורה","Black long sleeve tee","שחור","shirt",99,0,"a plain black long-sleeve crew-neck cotton top"),
("חולצת פשתן קצרה אבן","Stone linen shirt","אבן","shirt",149,0,"a stone beige short-sleeve linen button-up shirt"),
("חולצת טי אוברסייז אופוויט","Off-white oversized tee","אופוויט","shirt",95,0,"an off-white oversized heavyweight cotton t-shirt"),
("מכנסיים בגזרה ישרה שחורים","Black straight trousers","שחור","pants",149,0,"black straight-leg tailored trousers"),
("מכנסיים בגזרה ישרה אפור פחם","Charcoal straight trousers","אפור פחם","pants",149,0,"charcoal grey straight-leg tailored trousers"),
("מכנסי צ'ינו בז'","Beige chinos","בז'","pants",159,0,"beige cotton chino trousers"),
("מכנסי צ'ינו נייבי","Navy chinos","נייבי","pants",159,0,"navy blue cotton chino trousers"),
("ג'ינס ישר כחול כהה","Dark indigo jeans","כחול כהה","pants",179,199,"dark indigo straight-leg denim jeans"),
("ג'ינס ישר שחור","Black jeans","שחור","pants",179,0,"black straight-leg denim jeans"),
("מכנסי פשתן אבן","Stone linen trousers","אבן","pants",169,0,"stone beige relaxed linen trousers"),
("מכנסי טרנינג אפורים","Grey joggers","אפור","pants",129,0,"heather grey cotton jogger sweatpants"),
("סניקרס עור מינימליסטיות לבנות","White leather sneakers","לבן","shoes",289,0,"a pair of white minimal low-top leather sneakers"),
("סניקרס עור מינימליסטיות שחורות","Black leather sneakers","שחור","shoes",289,0,"a pair of black minimal low-top leather sneakers"),
("נעלי בד נמוכות לבנות","White canvas sneakers","לבן","shoes",199,0,"a pair of white low-top canvas sneakers"),
("נעלי בד נמוכות שחורות","Black canvas sneakers","שחור","shoes",199,0,"a pair of black low-top canvas sneakers"),
("נעלי לואפר עור שחורות","Black leather loafers","שחור","shoes",329,0,"a pair of black leather penny loafers"),
("נעלי דרבי עור חומות","Brown leather derby shoes","חום","shoes",349,0,"a pair of brown leather derby lace-up shoes"),
("כובע מצחייה שחור","Black cap","שחור","hat",69,0,"a plain black cotton baseball cap, no logo"),
("כובע מצחייה בז'","Beige cap","בז'","hat",69,0,"a plain beige cotton baseball cap, no logo"),
("כובע מצחייה נייבי","Navy cap","נייבי","hat",69,0,"a plain navy blue cotton baseball cap, no logo"),
("כובע גרב שחור","Black beanie","שחור","hat",59,0,"a plain black ribbed knit beanie"),
("כובע גרב אפור","Grey beanie","אפור","hat",59,0,"a plain grey ribbed knit beanie"),
("כובע באקט אבן","Stone bucket hat","אבן","hat",79,0,"a stone beige cotton bucket hat"),
]
SIZES={"shirt":S,"pants":P,"shoes":SH,"hat":H}
CAT={"shirt":"clothing","pants":"clothing","shoes":"shoes","hat":"accessories"}
PREFIX={"shirt":"SHIMI-B-TEE","pants":"SHIMI-B-PNT","shoes":"SHIMI-B-SHO","hat":"SHIMI-B-HAT"}
def prompt(d): return f"Studio product photo of {d}, single item centered, soft natural shadow, warm beige seamless background, premium minimal basics fashion catalog style, no text, no logo, no person, 4:3 landscape"
