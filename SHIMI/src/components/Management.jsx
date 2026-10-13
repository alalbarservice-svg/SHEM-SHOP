import { ChannelProduct, ChannelConsole } from "./ChannelManagement";
import { useEffect, useState } from "react";
import { adminApi } from "../admin-api";
import { exportCSV } from "./AdminOperations";
import { money } from "../commerce";
const draftId = () =>
  crypto.randomUUID?.() ||
  "draft-" +
    Date.now().toString(36) +
    "-" +
    Math.random().toString(36).slice(2);
const api = (p, method = "GET", d) =>
  adminApi("manage/" + p, {
    method,
    ...(d ? { body: JSON.stringify(d) } : {}),
  });
const text = (key, label, type = "text", required = false) => ({
  key,
  label,
  type,
  required,
});
const customer = [
  text("name", "שם מלא", "text", true),
  text("email", "אימייל", "email", true),
  text("phone", "טלפון"),
  text("city", "עיר"),
  text("street", "רחוב ומספר"),
  text("apartment", "דירה / קומה"),
  text("postcode", "מיקוד"),
];
const definitions = {
  products: {
    title: "מוצרים",
    add: "הוספת מוצר",
    endpoint: "products",
    defaults: {
      he: "",
      en: "",
      brand: "",
      cat: "clothing",
      price: 199,
      stock: 0,
      sizes: ["S", "M", "L", "XL"],
      active: false,
      color: "black",
      variants: [],
    },
    fields: [
      text("he", "שם פריט", "text", true),
      text("en", "שם באנגלית"),
      text("brand", "מותג"),
      text("cat", "קטגוריה", "category"),
      text("sku", "מק״ט SHIMI"),
      text("homeSku", "מק״ט מקביל באתר הבית"),
      text("homeProductId", "מזהה מוצר באתר הבית"),
      text("price", "מחיר", "number", true),
      text("previousPrice", "מחיר לפני מבצע", "number"),
      text("stock", "מלאי כולל", "number", true),
      text("sizesText", "מידות בהפרדה בפסיק"),
      text("color", "צבע"),
      text("description", "תיאור", "textarea"),
      text("material", "הרכב בד"),
      text("care", "הוראות טיפול"),
      text("active", "הצגת המוצר בחנות", "checkbox"),
    ],
  },
  collections: {
    title: "קולקציות",
    add: "הוספת קולקציה",
    defaults: {
      title: "",
      description: "",
      productIds: [],
      active: false,
      position: 0,
    },
    fields: [
      text("title", "שם הקולקציה", "text", true),
      text("description", "תיאור", "textarea"),
      text("position", "סדר תצוגה", "number"),
      text("active", "פרסום קולקציה", "checkbox"),
    ],
  },
  crm: {
    title: "כרטיסי לקוח",
    kind: "customers",
    add: "הוספת לקוח",
    defaults: {},
    fields: [
      ...customer,
      text("birthday", "תאריך לידה", "date"),
      text("preferredSize", "מידה מועדפת"),
      text("tags", "תגיות"),
      text("notes", "הערות שירות", "textarea"),
    ],
  },
  cards: {
    title: "כרטיסי מועדון",
    add: "הוספת כרטיס מועדון",
    defaults: { tier: "standard", status: "active" },
    fields: [
      ...customer,
      text("birthday", "תאריך לידה", "date"),
      text("tier", "דרגה", "tier"),
      text("status", "מצב חברות", "memberStatus"),
      text("notes", "הערות", "textarea"),
    ],
  },
  members: {
    title: "חברי מועדון",
    kind: "cards",
    add: "הוספת חבר מועדון",
    defaults: { tier: "standard", status: "active" },
    fields: [
      ...customer,
      text("birthday", "תאריך לידה", "date"),
      text("tier", "דרגה", "tier"),
      text("status", "מצב חברות", "memberStatus"),
      text("notes", "הערות", "textarea"),
    ],
  },
  requests: {
    title: "שירות והחזרות",
    add: "פתיחת פנייה",
    defaults: { type: "support", status: "new" },
    fields: [
      ...customer,
      text("orderNumber", "מספר הזמנה"),
      text("type", "סוג פנייה", "requestType"),
      text("status", "מצב פנייה", "requestStatus"),
      text("message", "פניית הלקוח", "textarea"),
      text("notes", "הערות טיפול", "textarea"),
      text("collectionDate", "מועד איסוף", "date"),
      text("tracking", "מספר מעקב איסוף"),
      text("resolution", "סיכום טיפול", "textarea"),
    ],
  },
  audit: {
    title: "פעילויות ידניות",
    kind: "activities",
    add: "הוספת פעילות ידנית",
    defaults: { status: "open" },
    fields: [
      text("title", "כותרת", "text", true),
      text("reason", "סיבה", "textarea", true),
      text("notes", "פרטי פעילות", "textarea"),
      text("dueDate", "מועד לביצוע", "date"),
      text("status", "מצב פעילות", "activityStatus"),
    ],
  },
  promotions: {
    title: "מבצעים",
    add: "הוספת מבצע",
    endpoint: "promotions",
    defaults: { discount: 5, active: false, excludeSale: true, minSubtotal: 0 },
    fields: [
      text("code", "קוד מבצע", "text", true),
      text("discount", "אחוז הנחה", "number", true),
      text("minSubtotal", "סכום מינימום", "number"),
      text("startsAt", "תחילת תוקף", "date"),
      text("endsAt", "סיום תוקף", "date"),
      text("excludeSale", "החרגת פריטי מבצע", "checkbox"),
      text("active", "הפעלת המבצע", "checkbox"),
    ],
  },
  orders: {
    title: "הזמנות",
    add: "יצירת הזמנה ידנית",
    defaults: {
      customer: {},
      items: [],
      shippingCost: 49,
      shippingMethod: "standard",
      discount: 0,
      status: "draft",
    },
    fields: [
      text("status", "מצב הזמנה", "orderStatus"),
      text("shippingCost", "עלות משלוח", "number"),
      text("shippingMethod", "שיטת משלוח"),
      text("discount", "הנחה בשקלים", "number"),
      text("tracking", "מספר מעקב"),
      text("notes", "הערות", "textarea"),
    ],
  },
};
const settingsDefinitions = {
  invoice: {
    title: "תבנית חשבונית עסקה של SHIMI",
    defaults: {
      displayName: "SHIMI",
      documentTitle: "חשבונית עסקה",
      numberPrefix: "SHIMI-",
      vatMode: "",
      vatRate: 0,
    },
    fields: [
      text("legalName", "שם העסק המשפטי"),
      text("registrationNumber", "מספר עסק"),
      text("address", "כתובת העסק"),
      text("phone", "טלפון העסק"),
      text("email", "אימייל העסק", "email"),
      text("displayName", "שם מסחרי במסמך"),
      text("documentTitle", "כותרת מסמך"),
      text("numberPrefix", "קידומת מספור"),
      text("vatMode", "מצב מע״מ", "vatMode"),
      text("vatRate", "אחוז מע״מ", "number"),
      text("documentFooter", "טקסט תחתון במסמך", "textarea"),
    ],
  },
  home: {
    title: "חיבור סליקה דרך אתר הבית",
    defaults: { enabled: false, protocolVersion: "shem-home-v1" },
    fields: [
      text("siteUrl", "כתובת HTTPS של אתר הבית", "url"),
      text("displayName", "שם אתר הבית"),
      text("protocolVersion", "גרסת פרוטוקול"),
      text("enabled", "אישור הפעלת חיבור לאחר בדיקה", "checkbox"),
    ],
  },
  loyalty: {
    title: "הגדרות הטבות ונאמנות",
    defaults: {
      enabled: false,
      earnPercent: 3,
      maxRedeemPercent: 10,
      vipSpend: 1000,
      vipOrders: 4,
      pendingDays: 14,
      expiryDays: 365,
      minRedemption: 20,
      birthdayBenefit: 0,
      excludeSale: true,
      allowStacking: false,
      grossMarginPercent: 20,
    },
    fields: [
      text("enabled", "הפעלת צבירה לאחר תשלום", "checkbox"),
      text("earnPercent", "אחוז צבירה", "number"),
      text("maxRedeemPercent", "תקרת מימוש באחוזים", "number"),
      text("minRedemption", "מינימום למימוש בשקלים", "number"),
      text("vipSpend", "סף רכישות לדרגת VIP בשקלים", "number"),
      text("vipOrders", "סף מספר הזמנות לדרגת VIP", "number"),
      text("pendingDays", "ימי המתנה לצבירה", "number"),
      text("expiryDays", "תוקף צבירה בימים", "number"),
      text("birthdayBenefit", "הטבת יום הולדת בשקלים", "number"),
      text("excludeSale", "החרגת מבצעים מצבירה", "checkbox"),
      text("allowStacking", "אפשרות כפל הטבות", "checkbox"),
      text("grossMarginPercent", "רווח גולמי משוער באחוזים", "number"),
      text("benefits", "תיאור הטבות המועדון", "textarea"),
    ],
  },
  operations: {
    title: "הגדרות תפעול",
    defaults: {
      standardPrice: 49,
      expressPrice: 199,
      standardDays: 7,
      expressDays: 1,
      lowStockThreshold: 3,
      instagram: "new.shem",
      whatsapp: "972529586766",
      checkoutEnabled: false,
    },
    fields: [
      text("standardPrice", "מחיר משלוח רגיל", "number"),
      text("expressPrice", "מחיר משלוח מהיר", "number"),
      text("standardDays", "ימי עסקים למשלוח רגיל", "number"),
      text("expressDays", "ימי עסקים למשלוח מהיר", "number"),
      text("lowStockThreshold", "סף התראת מלאי נמוך", "number"),
      text("serviceAreas", "אזורי שירות", "textarea"),
      text("instagram", "שם משתמש באינסטגרם"),
      text("whatsapp", "וואטסאפ בפורמט בינלאומי"),
      text("checkoutEnabled", "הפעלת רכישה לאחר חיבור ובדיקה", "checkbox"),
    ],
  },
  content: {
    title: "תוכן האתר",
    defaults: {
      heroTitle: "DRESS\nWITH INTENT.",
      heroSubtitle: "הסטייל שלך. בלי פשרות.",
      arrivalsTitle: "חדשים באתר שלנו",
      saleTitle: "פריטים בסוף עונה",
      heroImages: [],
      blocks: [],
    },
    fields: [
      text("heroTitle", "כותרת הבלוק הראשי", "textarea"),
      text("heroSubtitle", "תת כותרת ראשית"),
      text("arrivalsTitle", "כותרת פריטים חדשים"),
      text("saleTitle", "כותרת סוף עונה"),
      text("announcement", "הודעה בראש האתר"),
    ],
  },
  business: {
    title: "פרטי העסק",
    defaults: {},
    fields: [
      text("legalName", "שם משפטי"),
      text("registrationNumber", "מספר עסק"),
      text("displayName", "שם מסחרי"),
      text("address", "כתובת"),
      text("phone", "טלפון"),
      text("email", "אימייל", "email"),
      text("taxStatus", "מעמד העסק"),
      text("openingHours", "שעות שירות"),
      text("website", "אתר העסק", "url"),
      text("accessibilityContact", "איש קשר לנגישות"),
      text("serviceAreas", "אזורי משלוח", "textarea"),
      text("returnCollectionFee", "עלות איסוף החזרה"),
    ],
  },
};
const options = {
  category: [
    ["clothing", "בגדים"],
    ["shoes", "נעליים"],
    ["accessories", "אקססוריז"],
  ],
  tier: [
    ["standard", "SHIMI CLUB"],
    ["vip", "SHIMI PRIVATE"],
  ],
  memberStatus: [
    ["active", "פעיל"],
    ["suspended", "מושהה"],
  ],
  requestType: [
    ["support", "שירות"],
    ["cancel", "ביטול"],
    ["return", "החזרה"],
    ["exchange", "החלפה"],
  ],
  requestStatus: [
    ["new", "חדשה"],
    ["reviewing", "בטיפול"],
    ["collection", "תיאום איסוף"],
    ["resolved", "נסגרה"],
    ["rejected", "נדחתה"],
  ],
  activityStatus: [
    ["open", "פתוחה"],
    ["done", "הושלמה"],
  ],
  orderStatus: [
    ["draft", "טיוטה"],
    ["new", "חדשה"],
    ["processing", "בטיפול"],
    ["shipped", "נשלחה"],
    ["completed", "הושלמה"],
    ["cancelled", "מבוטלת"],
  ],
  vatMode: [
    ["exempt", "ללא מע״מ"],
    ["included", "המחירים כוללים מע״מ"],
  ],
};
function Fields({ fields, value, onChange, disabled = false }) {
  return (
    <div className="admin-form-grid">
      {fields.map((f) => (
        <label key={f.key}>
          {f.label}
          {options[f.type] ? (
            <select
              disabled={disabled || f.disabled}
              value={value[f.key] ?? options[f.type][0][0]}
              onChange={(e) => onChange({ ...value, [f.key]: e.target.value })}
            >
              {f.type === "vatMode" && <option value="">בחרו מעמד מע״מ</option>}
              {options[f.type].map(([v, t]) => (
                <option key={v} value={v}>
                  {t}
                </option>
              ))}
            </select>
          ) : f.type === "textarea" ? (
            <textarea
              disabled={
                disabled ||
                f.disabled ||
                (f.key === "stock" && !!value.variants?.length)
              }
              required={f.required}
              value={value[f.key] ?? ""}
              onChange={(e) => onChange({ ...value, [f.key]: e.target.value })}
            />
          ) : (
            <input
              disabled={
                disabled ||
                f.disabled ||
                (f.key === "stock" && !!value.variants?.length)
              }
              required={f.required}
              type={f.type === "checkbox" ? "checkbox" : f.type}
              step={f.type === "number" ? "0.01" : undefined}
              {...(f.type === "checkbox"
                ? { checked: !!value[f.key] }
                : { value: value[f.key] ?? "" })}
              onChange={(e) =>
                onChange({
                  ...value,
                  [f.key]:
                    f.type === "checkbox"
                      ? e.target.checked
                      : f.type === "number"
                        ? e.target.value === ""
                          ? ""
                          : Number(e.target.value)
                        : e.target.value,
                })
              }
            />
          )}
        </label>
      ))}
    </div>
  );
}
function SettingForm({ kind, onNotice }) {
  const def = settingsDefinitions[kind],
    [value, setValue] = useState(def.defaults),
    [busy, setBusy] = useState(false),
    [ready, setReady] = useState(false),
    [error, setError] = useState("");
  useEffect(() => {
    api("settings/" + kind)
      .then((v) => {
        setValue({ ...def.defaults, ...v });
        setReady(true);
      })
      .catch((e) => setError(e.message));
  }, [kind]);
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      setValue(await api("settings/" + kind, "PUT", value));
      onNotice("ההגדרות נשמרו בשרת.");
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="admin-panel">
      <h2>{def.title}</h2>
      {kind === "invoice" && (
        <p>
          המסמך יופק לאחר אישור תשלום, עם פרטי ההזמנה והלקוח. חשבונית עסקה אינה
          חשבונית מס או קבלה. יש לבחור מעמד מע״מ בהתאם לעסק.
        </p>
      )}
      {kind === "home" && (
        <p>
          התשלום יוצג בתוך SHIMI דרך מסך מאובטח של אתר הבית. כתובת חיבור ומפתח
          חתימה יוגדרו בסודות השרת. הפעלת האפשרות כאן אינה מחברת אתר שטרם נבנה.
        </p>
      )}
      {kind === "loyalty" && (
        <p>
          נתון הרווח המשוער הוא 20%. צבירה מחושבת לאחר תשלום. יש להגדיר הטבות
          לפי הרווחיות. מימוש אוטומטי בקופה מחייב זיהוי מאומת של חבר.
        </p>
      )}
      <form onSubmit={save}>
        <Fields
          fields={def.fields}
          value={value}
          onChange={setValue}
          disabled={!ready || busy}
        />
        {kind === "content" && (
          <>
            <h3>תמונות הבלוק הראשי</h3>
            {[0, 1, 2].map((i) => (
              <label key={i}>
                נתיב תמונת סלייד {i + 1}
                <input
                  aria-label={"נתיב תמונת סלייד " + (i + 1)}
                  placeholder="/assets/hero.webp"
                  value={value.heroImages?.[i] || ""}
                  onChange={(e) => {
                    const a = [...(value.heroImages || [])];
                    a[i] = e.target.value;
                    setValue({ ...value, heroImages: a });
                  }}
                />
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  aria-label={"העלאת תמונת סלייד " + (i + 1)}
                  disabled={busy || !ready}
                  onChange={async (e) => {
                    const file = e.target.files?.[0];
                    if (!file) return;
                    setBusy(true);
                    setError("");
                    try {
                      const result = await adminApi("media", {
                        method: "POST",
                        body: file,
                        headers: { "content-type": file.type },
                      });
                      setValue((previous) => {
                        const a = [...(previous.heroImages || [])];
                        a[i] = result.url;
                        return { ...previous, heroImages: a };
                      });
                    } catch (error) {
                      setError(error.message);
                    } finally {
                      setBusy(false);
                    }
                  }}
                />
                {value.heroImages?.[i] && (
                  <img
                    className="admin-editor-image"
                    src={value.heroImages[i]}
                    alt={"תצוגת סלייד " + (i + 1)}
                  />
                )}
              </label>
            ))}
            <h3>בלוקי תוכן</h3>
            <button
              type="button"
              onClick={() =>
                setValue({
                  ...value,
                  blocks: [
                    ...(value.blocks || []),
                    {
                      id: draftId(),
                      title: "",
                      text: "",
                      label: "",
                      link: "#/shop",
                      position: value.blocks?.length || 0,
                      active: false,
                    },
                  ],
                })
              }
            >
              הוספת בלוק תוכן
            </button>
            {(value.blocks || []).map((b, i) => (
              <fieldset key={b.id}>
                <legend>בלוק {i + 1}</legend>
                <Fields
                  fields={[
                    text("title", "כותרת בלוק"),
                    text("text", "טקסט בלוק", "textarea"),
                    text("label", "טקסט קישור"),
                    text("link", "יעד קישור פנימי"),
                    text("position", "סדר בלוק", "number"),
                    text("active", "הצגת בלוק", "checkbox"),
                  ]}
                  value={b}
                  onChange={(v) =>
                    setValue({
                      ...value,
                      blocks: value.blocks.map((x) => (x.id === b.id ? v : x)),
                    })
                  }
                />
                <button
                  type="button"
                  onClick={() =>
                    setValue({
                      ...value,
                      blocks: value.blocks.filter((x) => x.id !== b.id),
                    })
                  }
                >
                  הסרת בלוק מהטיוטה
                </button>
              </fieldset>
            ))}
          </>
        )}
        {error && <p role="alert">{error}</p>}
        <button className="admin-primary" disabled={busy || !ready}>
          שמירת הגדרות {def.title}
        </button>
      </form>
      {kind === "invoice" && (
        <a
          href="/api/admin/manage/template-preview.pdf"
          target="_blank"
          rel="noreferrer"
        >
          תצוגה מקדימה של התבנית עם נתוני דוגמה
        </a>
      )}
    </section>
  );
}
export function Management({ section, onChange }) {
  const [linkedProduct, setLinkedProduct] = useState(false);
  const [rows, setRows] = useState([]),
    [products, setProducts] = useState([]),
    [ledger, setLedger] = useState([]),
    [systemLog, setSystemLog] = useState([]),
    [editor, setEditor] = useState(null),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true),
    [query, setQuery] = useState(""),
    [archived, setArchived] = useState(false),
    [confirm, setConfirm] = useState(null),
    [health, setHealth] = useState(null),
    [adjust, setAdjust] = useState(null),
    [payment, setPayment] = useState(null),
    [orderDetail, setOrderDetail] = useState(null),
    [paymentEdit, setPaymentEdit] = useState(null),
    [club, setClub] = useState(null),
    [subscribers, setSubscribers] = useState([]),
    [loyaltyCards, setLoyaltyCards] = useState([]),
    [documents, setDocuments] = useState([]),
    [mail, setMail] = useState([]);
  useEffect(() => {
    setLinkedProduct(false);
  }, [editor?.id]);
  const def = definitions[section],
    kind = def?.kind || section;
  const load = async () => {
    setLoading(true);
    setError("");
    try {
      setProducts(await adminApi("products"));
      if (def) setRows(await api(kind));
      if (section === "orders") {
        setLoyaltyCards(await api("cards"));
        setDocuments(await api("documents"));
        setMail(await api("mail"));
      }
      if (section === "inventory") {
        setRows(await api("stock"));
      }
      if (["members", "cards"].includes(section)) {
        setLedger(await api("loyalty"));
        setClub(
          (await adminApi("settings")).club || {
            enabled: false,
            privacyUrl: "#/privacy",
          },
        );
        setSubscribers(await adminApi("members"));
      }
      if (section === "audit") setSystemLog(await adminApi("audit"));
      if (["control", "integrations"].includes(section)) {
        setHealth(await api("status"));
        setDocuments(await api("documents"));
        setMail(await api("mail"));
      }
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    load();
  }, [section]);
  const run = async (fn) => {
    if (busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await fn();
      await load();
      onChange?.();
      setNotice("השינוי נשמר בשרת.");
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };
  const update = (v) => setEditor(v);
  const save = async (e) => {
    e.preventDefault();
    await run(async () => {
      let v = { ...editor };
      if (section === "products") {
        v.sizes = (v.sizesText || "")
          .split(",")
          .map((x) => x.trim())
          .filter(Boolean);
        delete v.sizesText;
        if (v.variants?.length) {
          v.stock = v.variants.reduce((n, x) => n + Number(x.stock || 0), 0);
          v.sizeStock = Object.fromEntries(
            v.sizes.map((s) => [
              s,
              v.variants
                .filter((x) => x.size === s)
                .reduce((n, x) => n + Number(x.stock || 0), 0),
            ]),
          );
        } else if (v.sizeStock) {
          v.stock = Object.values(v.sizeStock).reduce(
            (n, x) => n + Number(x),
            0,
          );
        }
      }
      if (def.endpoint)
        await adminApi(def.endpoint + (v.id ? "/" + v.id : ""), {
          method: v.id ? "PUT" : "POST",
          body: JSON.stringify(v),
        });
      else await api(kind + (v.id ? "/" + v.id : ""), v.id ? "PUT" : "POST", v);
      setEditor(null);
    });
  };
  const newRecord = () =>
    setEditor({
      ...def.defaults,
      ...(section === "products"
        ? { sizesText: "S,M,L,XL", variants: [] }
        : {}),
      ...(section === "orders" ? { customer: {}, items: [] } : {}),
    });
  const visible = rows.filter(
    (r) =>
      !!r.archived === archived &&
      JSON.stringify(r).toLowerCase().includes(query.toLowerCase()),
  );
  const recordName = (r) =>
    r.he || r.title || r.name || r.email || r.code || r.customer?.name || r.id;
  const productField = (items, setItems) => (
    <>
      <button
        type="button"
        onClick={() =>
          setItems([
            ...items,
            { productId: "", size: "", variantId: "", qty: 1 },
          ])
        }
      >
        הוספת פריט להזמנה
      </button>
      {items.map((i, n) => {
        const p = products.find((p) => p.id === i.productId),
          change = (v) => setItems(items.map((x, k) => (k === n ? v : x)));
        return (
          <fieldset key={n}>
            <legend>פריט {n + 1}</legend>
            <label>
              מוצר
              <select
                required
                value={i.productId}
                onChange={(e) =>
                  change({
                    ...i,
                    productId: e.target.value,
                    size: "",
                    variantId: "",
                  })
                }
              >
                <option value="">בחירת מוצר</option>
                {products
                  .filter((p) => !p.archived)
                  .map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.he} / {p.sku} / {money(p.price)}
                    </option>
                  ))}
              </select>
            </label>
            {p?.variants?.length ? (
              <label>
                גרסת מידה וצבע
                <select
                  required
                  value={i.variantId || ""}
                  onChange={(e) => {
                    const v = p.variants.find((v) => v.id === e.target.value);
                    change({
                      ...i,
                      variantId: v?.id || "",
                      size: v?.size || "",
                    });
                  }}
                >
                  <option value="">בחרו גרסה</option>
                  {p.variants.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.size} / {v.color} / {v.sku}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <label>
                מידה
                <select
                  required
                  value={i.size}
                  onChange={(e) => change({ ...i, size: e.target.value })}
                >
                  <option value="">בחירת מידה</option>
                  {p?.sizes?.map((s) => (
                    <option key={s}>{s}</option>
                  ))}
                </select>
              </label>
            )}
            <label>
              כמות
              <input
                required
                type="number"
                min="1"
                max="1000"
                step="1"
                value={i.qty}
                onChange={(e) => change({ ...i, qty: Number(e.target.value) })}
              />
            </label>
            <button
              type="button"
              onClick={() => setItems(items.filter((_, k) => k !== n))}
            >
              הסרת פריט מהטיוטה
            </button>
          </fieldset>
        );
      })}
    </>
  );
  return (
    <div className="management-workspace">
      {error && (
        <p role="alert" className="admin-alert">
          {error}
        </p>
      )}
      {notice && (
        <p role="status" className="admin-success">
          {notice}
        </p>
      )}
      <div className="admin-toolbar">
        <button disabled={busy} onClick={load}>
          רענון מהשרת
        </button>
        {def && (
          <button className="admin-primary" disabled={busy} onClick={newRecord}>
            {def.add}
          </button>
        )}
      </div>
      {def && (
        <section className="admin-panel">
          <h2>{def.title}</h2>
          <div className="admin-toolbar">
            <label>
              חיפוש
              <input value={query} onChange={(e) => setQuery(e.target.value)} />
            </label>
            <label>
              <input
                type="checkbox"
                checked={archived}
                onChange={(e) => setArchived(e.target.checked)}
              />
              הצגת ארכיון
            </label>
            <button
              disabled={!visible.length}
              onClick={() =>
                exportCSV(
                  "shem-" + kind,
                  visible.map((r) => ({
                    id: r.id,
                    name: recordName(r),
                    email: r.email || r.customer?.email,
                    status: r.status,
                    price: r.price,
                    total: r.total,
                    archived: r.archived,
                  })),
                )
              }
            >
              ייצוא CSV
            </button>
          </div>
          {loading ? (
            <p role="status">טוען...</p>
          ) : (
            <div className="admin-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>רשומה</th>
                    <th>פרטים</th>
                    <th>מצב</th>
                    <th>פעולות</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((r) => (
                    <tr key={r.id}>
                      <td>
                        {recordName(r)}
                        <small>{r.cardNumber || r.sku || r.id}</small>
                      </td>
                      <td>
                        {r.email || r.customer?.email || r.description || ""}
                        {section === "orders" && (
                          <p>
                            {money(r.total)} |{" "}
                            {r.paymentStatus === "paid" ? "שולם" : "לא שולם"}
                          </p>
                        )}
                        {section === "products" && (
                          <p>
                            {money(r.price)} | מלאי {r.stock}
                          </p>
                        )}
                        {["cards", "members"].includes(section) && (
                          <p>
                            {(r.effectiveTier || r.tier) === "vip"
                              ? "SHIMI PRIVATE"
                              : "SHIMI CLUB"}{" "}
                            | יתרה זמינה{" "}
                            {money(
                              ledger
                                .filter(
                                  (l) =>
                                    l.memberId === r.id &&
                                    l.availableAt <= new Date().toISOString() &&
                                    (!l.expiresAt ||
                                      l.expiresAt > new Date().toISOString()),
                                )
                                .reduce((n, l) => n + l.deltaCents, 0) / 100,
                            )}
                          </p>
                        )}
                      </td>
                      <td>
                        {r.archived
                          ? "בארכיון"
                          : r.status || (r.active ? "מפורסם" : "טיוטה")}
                      </td>
                      <td>
                        <div className="admin-row-actions">
                          {!r.archived && (
                            <button
                              onClick={() =>
                                setEditor({
                                  ...r,
                                  ...(section === "products"
                                    ? { sizesText: r.sizes.join(",") }
                                    : {}),
                                  ...(section === "orders"
                                    ? {
                                        shippingCost: r.shippingCents / 100,
                                        discount: r.discountCents / 100,
                                      }
                                    : {}),
                                })
                              }
                            >
                              עריכה
                            </button>
                          )}
                          <button
                            onClick={() =>
                              setConfirm({
                                record: r,
                                action: r.archived ? "restore" : "archive",
                              })
                            }
                          >
                            {r.archived ? "שחזור" : "מחיקה לארכיון"}
                          </button>
                          {section === "orders" && (
                            <>
                              <button onClick={() => setOrderDetail(r)}>
                                פרטי הזמנה ותשלום
                              </button>
                              {r.paymentStatus === "paid" && (
                                <button
                                  onClick={() =>
                                    setPaymentEdit({
                                      order: r,
                                      details: r.paymentDetails || {
                                        method: "card",
                                      },
                                    })
                                  }
                                >
                                  עדכון פרטי זיהוי עסקה
                                </button>
                              )}
                              {documents.some((d) => d.orderId === r.id) && (
                                <a
                                  href={
                                    "/api/admin/manage/documents/" +
                                    r.id +
                                    "/pdf"
                                  }
                                  target="_blank"
                                  rel="noreferrer"
                                >
                                  חשבונית עסקה PDF
                                </a>
                              )}
                            </>
                          )}
                          {section === "orders" &&
                            r.paymentStatus === "paid" && (
                              <button
                                disabled={busy}
                                onClick={() =>
                                  run(() =>
                                    api(
                                      "orders/" + r.id + "/issue-document",
                                      "POST",
                                      {},
                                    ),
                                  )
                                }
                              >
                                הפקת מסמך / ניסיון שליחה
                              </button>
                            )}
                          {section === "orders" &&
                            !r.archived &&
                            r.paymentStatus !== "paid" && (
                              <>
                                <button
                                  onClick={() =>
                                    setPayment({ order: r, reference: "" })
                                  }
                                >
                                  רישום תשלום חיצוני
                                </button>
                                {r.redemptionId ? (
                                  <button
                                    disabled={busy}
                                    onClick={() =>
                                      run(() =>
                                        api(
                                          "orders/" + r.id + "/release-credit",
                                          "POST",
                                          {},
                                        ),
                                      )
                                    }
                                  >
                                    ביטול מימוש הטבה
                                  </button>
                                ) : (
                                  <button
                                    onClick={() =>
                                      setAdjust({
                                        orderId: r.id,
                                        memberId: "",
                                        amount: 0,
                                        reason: "מימוש להזמנה",
                                      })
                                    }
                                  >
                                    מימוש יתרת מועדון להזמנה
                                  </button>
                                )}
                                <button
                                  disabled={busy}
                                  onClick={() =>
                                    run(async () => {
                                      const result = await api(
                                        "orders/" + r.id + "/payment-link",
                                        "POST",
                                        {},
                                      );
                                      setPayment({
                                        order: r,
                                        embedUrl: result.embedUrl,
                                      });
                                    })
                                  }
                                >
                                  יצירת קישור לתשלום
                                </button>
                              </>
                            )}
                          {["cards", "members"].includes(section) &&
                            !r.archived && (
                              <>
                                <button
                                  onClick={() =>
                                    setAdjust({
                                      memberId: r.id,
                                      amount: 0,
                                      reason: "",
                                    })
                                  }
                                >
                                  עדכון יתרת הטבה
                                </button>
                                <button
                                  disabled={busy}
                                  onClick={() =>
                                    run(() =>
                                      api(
                                        "cards/" + r.id + "/birthday",
                                        "POST",
                                        {},
                                      ),
                                    )
                                  }
                                >
                                  הענקת הטבת יום הולדת
                                </button>
                              </>
                            )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!visible.length && (
                <p>אין רשומות בתצוגה זו. אפשר להוסיף רשומה חדשה.</p>
              )}
            </div>
          )}
        </section>
      )}
      {confirm && (
        <section className="admin-panel" role="region" aria-label="אישור ארכוב">
          <h3>
            {confirm.action === "archive" ? "מחיקה לארכיון" : "שחזור"}:{" "}
            {recordName(confirm.record)}
          </h3>
          <p>
            הפעולה אינה מבטלת תשלום או מפיקה זיכוי. רשומות ששולמו ותיעוד כספי
            נשמרים.
          </p>
          <button
            disabled={busy}
            onClick={() =>
              run(async () => {
                await api(
                  kind + "/" + confirm.record.id + "/" + confirm.action,
                  "POST",
                  { revision: confirm.record.revision },
                );
                setConfirm(null);
              })
            }
          >
            אישור {confirm.action === "archive" ? "ארכוב" : "שחזור"}
          </button>
          <button onClick={() => setConfirm(null)}>ביטול פעולה</button>
        </section>
      )}
      {editor && (
        <section className="admin-panel admin-inline-editor">
          <h2>
            {editor.id ? "עריכת" : "יצירת"} {def.title}
          </h2>
          <button onClick={() => setEditor(null)}>סגירה ללא שמירה</button>
          <form onSubmit={save}>
            {section === "orders" && (
              <>
                <h3>פרטי לקוח ומשלוח</h3>
                <Fields
                  fields={customer}
                  value={editor.customer || {}}
                  onChange={(c) => update({ ...editor, customer: c })}
                  disabled={editor.paymentStatus === "paid"}
                />
                {editor.paymentStatus !== "paid" &&
                  productField(editor.items, (v) =>
                    update({ ...editor, items: v }),
                  )}
                {editor.paymentStatus === "paid" && (
                  <p>
                    פריטי הזמנה ששולמה נשמרים ללא שינוי. ביטול מצב טיפול אינו
                    החזר כספי.
                  </p>
                )}
              </>
            )}
            <Fields
              fields={def.fields
                .filter(
                  (f) =>
                    !(
                      section === "orders" &&
                      editor.paymentStatus === "paid" &&
                      ["shippingCost", "discount", "shippingMethod"].includes(
                        f.key,
                      )
                    ),
                )
                .map((f) => ({
                  ...f,
                  disabled:
                    import.meta.env.VITE_SHOP_ROLE === "home" &&
                    linkedProduct &&
                    [
                      "sku",
                      "price",
                      "previousPrice",
                      "stock",
                      "sizesText",
                      "homeSku",
                      "homeProductId",
                    ].includes(f.key),
                }))}
              value={editor}
              onChange={update}
            />
            {section === "products" && (
              <>
                <ChannelProduct product={editor} onChange={setLinkedProduct} />
                <label>
                  תמונת מוצר
                  <input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={async (e) => {
                      const f = e.target.files[0];
                      if (!f) return;
                      setBusy(true);
                      try {
                        const result = await adminApi("media", {
                          method: "POST",
                          body: f,
                          headers: { "content-type": f.type },
                        });
                        update({ ...editor, imageUrl: result.url });
                      } catch (e) {
                        setError(e.message);
                      } finally {
                        setBusy(false);
                      }
                    }}
                  />
                </label>
                {editor.imageUrl && (
                  <img
                    className="admin-editor-image"
                    src={editor.imageUrl}
                    alt="תמונת מוצר"
                  />
                )}
                <h3>גרסאות מידה, צבע ומיפוי אתר הבית</h3>
                <button
                  type="button"
                  onClick={() =>
                    update({
                      ...editor,
                      variants: [
                        ...(editor.variants || []),
                        {
                          id: draftId(),
                          size: "",
                          color: "",
                          sku: "",
                          homeSku: "",
                          stock: 0,
                        },
                      ],
                    })
                  }
                >
                  הוספת גרסת מוצר
                </button>
                {(editor.variants || []).map((v) => (
                  <fieldset key={v.id}>
                    <legend>גרסת מוצר</legend>
                    <Fields
                      fields={[
                        text("size", "מידה"),
                        text("color", "צבע גרסה"),
                        text("sku", "מק״ט גרסה"),
                        text("homeSku", "מק״ט גרסה באתר הבית"),
                        text("stock", "מלאי גרסה", "number"),
                      ]}
                      disabled={
                        import.meta.env.VITE_SHOP_ROLE === "home" &&
                        linkedProduct
                      }
                      value={v}
                      onChange={(next) =>
                        update({
                          ...editor,
                          variants: editor.variants.map((x) =>
                            x.id === v.id ? next : x,
                          ),
                        })
                      }
                    />
                    <button
                      type="button"
                      onClick={() =>
                        update({
                          ...editor,
                          variants: editor.variants.filter(
                            (x) => x.id !== v.id,
                          ),
                        })
                      }
                    >
                      הסרת גרסה מהטיוטה
                    </button>
                  </fieldset>
                ))}
                {editor.sizeStock && !editor.variants?.length && (
                  <fieldset>
                    <legend>מלאי לפי מידה</legend>
                    {Object.keys(editor.sizeStock).map((size) => (
                      <label key={size}>
                        מלאי {size}
                        <input
                          type="number"
                          min="0"
                          step="1"
                          value={editor.sizeStock[size]}
                          onChange={(e) =>
                            update({
                              ...editor,
                              sizeStock: {
                                ...editor.sizeStock,
                                [size]: Number(e.target.value),
                              },
                            })
                          }
                        />
                      </label>
                    ))}
                  </fieldset>
                )}
              </>
            )}
            {section === "collections" && (
              <fieldset>
                <legend>שיוך מוצרים</legend>
                {products
                  .filter((p) => !p.archived)
                  .map((p) => (
                    <label key={p.id}>
                      <input
                        type="checkbox"
                        checked={editor.productIds.includes(p.id)}
                        onChange={(e) =>
                          update({
                            ...editor,
                            productIds: e.target.checked
                              ? [...editor.productIds, p.id]
                              : editor.productIds.filter((id) => id !== p.id),
                          })
                        }
                      />
                      {p.he}
                    </label>
                  ))}
              </fieldset>
            )}
            {section === "audit" && editor.history?.length > 0 && (
              <details>
                <summary>היסטוריית פעילות ידנית</summary>
                {editor.history.map((h, i) => (
                  <p key={i}>
                    {h.changedAt} | {h.actor} | {h.title} | {h.reason} |{" "}
                    {h.notes}
                  </p>
                ))}
              </details>
            )}
            <button className="admin-primary" disabled={busy}>
              שמירה בשרת
            </button>
          </form>
        </section>
      )}
      {section === "inventory" && (
        <Inventory products={products} rows={rows} run={run} />
      )}
      {["cards", "members"].includes(section) && (
        <>
          <SettingForm
            kind="loyalty"
            onNotice={async (message) => {
              setNotice(message);
              if (["integrations", "control"].includes(section)) {
                try {
                  setHealth(await api("status"));
                } catch (e) {
                  setError(e.message);
                }
              }
            }}
          />
          {section === "members" && club && (
            <section className="admin-panel">
              <h2>הרשמה ודיוור בהסכמה</h2>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  run(() =>
                    adminApi("settings", {
                      method: "PUT",
                      body: JSON.stringify({ key: "club", value: club }),
                    }),
                  );
                }}
              >
                <Fields
                  fields={[
                    text("title", "כותרת מועדון"),
                    text("description", "תיאור מועדון", "textarea"),
                    text("benefits", "הטבות באתר", "textarea"),
                    text("privacyUrl", "קישור מדיניות פרטיות"),
                    text("enabled", "הפעלת הרשמה", "checkbox"),
                  ]}
                  value={club}
                  onChange={setClub}
                />
                <button disabled={busy}>שמירת הגדרות הרשמה</button>
              </form>
              <p>הוספה ידנית של חבר אינה יוצרת הסכמה לדיוור.</p>
              {subscribers.map((m) => (
                <div key={m.id}>
                  {m.email} | {m.status}{" "}
                  {m.status === "active" && (
                    <button
                      onClick={() =>
                        run(() =>
                          adminApi("members/" + m.id, {
                            method: "PATCH",
                            body: JSON.stringify({ status: "unsubscribed" }),
                          }),
                        )
                      }
                    >
                      הפסקת דיוור
                    </button>
                  )}
                </div>
              ))}
            </section>
          )}
          <section className="admin-panel">
            <h2>תנועות הטבות</h2>
            {ledger.map((l) => (
              <p key={l.id}>
                {l.memberId} | {money(l.deltaCents / 100)} |{" "}
                {l.reason || l.type} | זמין ב-{l.availableAt}
              </p>
            ))}
          </section>
        </>
      )}
      {adjust && (
        <section className="admin-panel">
          <h3>{adjust.orderId ? "מימוש הטבה להזמנה" : "תיקון יתרת הטבה"}</h3>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              run(async () => {
                await api(
                  adjust.orderId
                    ? "orders/" + adjust.orderId + "/redeem"
                    : "loyalty-adjust",
                  "POST",
                  adjust,
                );
                setAdjust(null);
              });
            }}
          >
            {adjust.orderId && (
              <label>
                חבר מועדון
                <select
                  required
                  value={adjust.memberId}
                  onChange={(e) =>
                    setAdjust({ ...adjust, memberId: e.target.value })
                  }
                >
                  <option value="">בחרו חבר תואם לאימייל ההזמנה</option>
                  {loyaltyCards
                    .filter((c) => !c.archived)
                    .map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} / {c.email}
                      </option>
                    ))}
                </select>
              </label>
            )}
            <Fields
              fields={[
                text("amount", "שינוי בשקלים, חיובי או שלילי", "number", true),
                text("reason", "סיבת השינוי", "textarea", true),
              ]}
              value={adjust}
              onChange={setAdjust}
            />
            <button disabled={busy}>שמירת תנועת הטבה</button>
            <button type="button" onClick={() => setAdjust(null)}>
              ביטול
            </button>
          </form>
        </section>
      )}
      {orderDetail && (
        <section className="admin-panel" aria-label="פרטי הזמנה ותשלום">
          <h3>הזמנה {orderDetail.id}</h3>
          <p>
            {orderDetail.customer.name} | {orderDetail.customer.email} |{" "}
            {orderDetail.customer.phone}
          </p>
          <p>
            {orderDetail.customer.street}, {orderDetail.customer.apartment},{" "}
            {orderDetail.customer.city} {orderDetail.customer.postcode}
          </p>
          <p>
            {orderDetail.shippingLabel ||
              (orderDetail.shippingMethod === "express"
                ? "שליח עד הבית - יום עסקים אחד"
                : "שליח עד הבית - 5-7 ימי עסקים")}
          </p>
          <table>
            <thead>
              <tr>
                <th>פריט ומק״ט</th>
                <th>מידה וצבע</th>
                <th>כמות</th>
                <th>מחיר יחידה</th>
                <th>סך שורה</th>
              </tr>
            </thead>
            <tbody>
              {orderDetail.items.map((i, n) => (
                <tr key={n}>
                  <td>
                    {i.name} | {i.sku}
                  </td>
                  <td>
                    {i.size} | {i.color}
                  </td>
                  <td>{i.qty}</td>
                  <td>{money(i.priceCents / 100)}</td>
                  <td>{money((i.priceCents * i.qty) / 100)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p>
            סך פריטים כולל מע״מ:{" "}
            {money(
              (orderDetail.subtotalCents - orderDetail.discountCents) / 100,
            )}
          </p>
          <p>
            מע״מ כלול בפריטים (18%):{" "}
            {money(
              (orderDetail.vatItemsCents ??
                Math.round(
                  ((orderDetail.subtotalCents - orderDetail.discountCents) *
                    18) /
                    118,
                )) / 100,
            )}
          </p>
          <p>
            משלוח: {money(orderDetail.shippingCents / 100)} | סה״כ לתשלום:{" "}
            {money(orderDetail.totalCents / 100)}
          </p>
          <h4>זיהוי תשלום</h4>
          <p>
            מצב: {orderDetail.paymentStatus} | אסמכתה:{" "}
            {orderDetail.paymentReference || "טרם התקבלה"}
          </p>
          <p>
            אמצעי: {orderDetail.paymentDetails?.method || "לא התקבל"} | מותג
            כרטיס: {orderDetail.paymentDetails?.brand || "לא התקבל"}
          </p>
          <p>
            4 ספרות אחרונות: {orderDetail.paymentDetails?.last4 || "לא התקבלו"}{" "}
            | בעל הכרטיס:{" "}
            {orderDetail.paymentDetails?.cardholderName || "לא התקבל"}
          </p>
          <p>
            מספר אישור:{" "}
            {orderDetail.paymentDetails?.authorizationCode || "לא התקבל"} |
            תשלומים: {orderDetail.paymentDetails?.installments || "לא התקבל"}
          </p>
          <h4>מסמכי ההזמנה</h4>
          {documents.some((d) => d.orderId === orderDetail.id) ? (
            <a
              href={"/api/admin/manage/documents/" + orderDetail.id + "/pdf"}
              target="_blank"
              rel="noreferrer"
            >
              חשבונית העסקה שנוצרה ללקוח - A4 PDF
            </a>
          ) : (
            <p>טרם הופקה חשבונית עסקה להזמנה זו.</p>
          )}
          <p>
            <a
              href={"/api/admin/manage/orders/" + orderDetail.id + "/order-pdf"}
              target="_blank"
              rel="noreferrer"
            >
              תעודת הזמנה למנהל - A4 PDF
            </a>
          </p>
          {mail
            .filter(
              (j) =>
                j.documentId === orderDetail.id || j.orderId === orderDetail.id,
            )
            .map((j) => (
              <p key={j.id}>
                שליחה אל {j.recipient}:{" "}
                {j.status === "sent"
                  ? "התקבל אצל ספק המייל"
                  : j.status === "failed"
                    ? "נכשלה"
                    : "ממתינה"}
              </p>
            ))}
          <button onClick={() => setOrderDetail(null)}>סגירה</button>
        </section>
      )}
      {paymentEdit && (
        <form
          className="admin-panel"
          onSubmit={(e) => {
            e.preventDefault();
            run(async () => {
              await api(
                "orders/" + paymentEdit.order.id + "/payment-details",
                "POST",
                { paymentDetails: paymentEdit.details },
              );
              setPaymentEdit(null);
            });
          }}
        >
          <h3>עדכון פרטי זיהוי עסקה</h3>
          <PaymentIdentification
            value={paymentEdit.details}
            onChange={(details) => setPaymentEdit({ ...paymentEdit, details })}
          />
          <button disabled={busy}>שמירת פרטי תשלום</button>
          <button type="button" onClick={() => setPaymentEdit(null)}>
            ביטול
          </button>
        </form>
      )}
      {payment && (
        <section className="admin-panel">
          <h3>תשלום הזמנה {payment.order.id}</h3>
          {payment.embedUrl ? (
            <>
              <a href={payment.embedUrl} target="_blank" rel="noreferrer">
                פתיחת קישור התשלום ללקוח
              </a>
              <label>
                קישור להעתקה
                <input readOnly value={payment.embedUrl} />
              </label>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                run(async () => {
                  await api("orders/" + payment.order.id + "/paid", "POST", {
                    reference: payment.reference,
                    paymentDetails: payment.details || {},
                  });
                  setPayment(null);
                });
              }}
            >
              <p>
                יש לרשום רק תשלום שהתקבל בפועל. הפעולה תעדכן מלאי ותפיק מסמך אם
                התבנית מלאה.
              </p>
              <label>
                אסמכתה חיצונית
                <input
                  required
                  value={payment.reference}
                  onChange={(e) =>
                    setPayment({ ...payment, reference: e.target.value })
                  }
                />
              </label>
              <PaymentIdentification
                value={payment.details || { method: "other" }}
                onChange={(details) => setPayment({ ...payment, details })}
              />
              <button disabled={busy}>אישור תשלום שהתקבל</button>
            </form>
          )}
          <button onClick={() => setPayment(null)}>סגירה</button>
        </section>
      )}
      {section === "content" && (
        <SettingForm
          kind="content"
          onNotice={async (message) => {
            setNotice(message);
            if (["integrations", "control"].includes(section)) {
              try {
                setHealth(await api("status"));
              } catch (e) {
                setError(e.message);
              }
            }
          }}
        />
      )}
      {section === "settings" && (
        <SettingForm
          kind="operations"
          onNotice={async (message) => {
            setNotice(message);
            if (["integrations", "control"].includes(section)) {
              try {
                setHealth(await api("status"));
              } catch (e) {
                setError(e.message);
              }
            }
          }}
        />
      )}
      {section === "business" && (
        <SettingForm
          kind="business"
          onNotice={async (message) => {
            setNotice(message);
            if (["integrations", "control"].includes(section)) {
              try {
                setHealth(await api("status"));
              } catch (e) {
                setError(e.message);
              }
            }
          }}
        />
      )}
      {["control", "integrations"].includes(section) && (
        <>
          <section className="admin-panel">
            <h2>מצב מוכנות וחיבורים</h2>
            {health && (
              <dl className="admin-integrations">
                {[
                  ["database", "מסד נתונים"],
                  ["media", "אחסון תמונות"],
                  ["invoiceComplete", "פרטי תבנית חשבונית מלאים"],
                  ["mailConfigured", "שירות מייל מוגדר"],
                  ["homeConfigured", "סודות חיבור אתר הבית מוגדרים"],
                  ["homeEnabled", "חיבור אתר הבית מאושר בממשק"],
                ].map(([k, l]) => (
                  <div key={k}>
                    <dt>{l}</dt>
                    <dd>{health[k] ? "מוגדר" : "חסר"}</dd>
                  </div>
                ))}
                <dt>מוצרים ללא מיפוי מלא</dt>
                <dd>{health.unmapped}</dd>
                <dt>הזמנות שלא שולמו</dt>
                <dd>{health.unpaid}</dd>
                <dt>מוצרים שאזלו</dt>
                <dd>{health.outOfStock}</dd>
                <dt>פניות פתוחות</dt>
                <dd>{health.openReturns}</dd>
                <dt>מיילים שנכשלו</dt>
                <dd>{health.failedMail}</dd>
                <dt>בדיקה אחרונה</dt>
                <dd>{health.checkedAt}</dd>
              </dl>
            )}
            <h3>מלאי נמוך</h3>
            {health?.lowStock?.map((p) => (
              <p key={p.id}>
                {p.name}: {p.stock}
              </p>
            ))}
            <p>
              הגדרת חיבור אינה בדיקת עסקה. אתר הבית עדיין דורש מימוש של
              הפרוטוקול ובדיקה מקצה לקצה.
            </p>
            <button
              disabled={busy}
              onClick={() =>
                run(async () => {
                  const result = await api("mail/process", "POST", {});
                  if (result.blocked) throw Error(result.blocked);
                })
              }
            >
              ניסיון שליחה לתור החשבוניות
            </button>
            <h3>תור מסמכים במייל</h3>
            {mail.map((j) => (
              <p key={j.id}>
                {j.recipient} |{" "}
                {{
                  pending: "ממתין",
                  sending: "בשליחה",
                  sent: "התקבל אצל שירות המייל",
                  failed: "נכשל",
                }[j.status] || j.status}{" "}
                | ניסיונות {j.attempts} {j.error}{" "}
                {j.status !== "sent" && (
                  <button
                    disabled={busy}
                    onClick={() =>
                      run(async () => {
                        const result = await api(
                          "mail/" + j.id + "/retry",
                          "POST",
                          {},
                        );
                        if (result.blocked) throw Error(result.blocked);
                      })
                    }
                  >
                    ניסיון שליחה מחדש
                  </button>
                )}
              </p>
            ))}
            <h3>חשבוניות עסקה שהופקו</h3>
            {documents.map((d) => (
              <p key={d.id}>
                {d.number} | {money(d.totalCents / 100)}{" "}
                <a
                  href={"/api/admin/manage/documents/" + d.id + "/pdf"}
                  target="_blank"
                  rel="noreferrer"
                >
                  פתיחת חשבונית עסקה A4 PDF
                </a>
              </p>
            ))}
          </section>
          {section === "integrations" && (
            <>
              <ChannelConsole />
              <SettingForm
                kind="invoice"
                onNotice={async (message) => {
                  setNotice(message);
                  if (["integrations", "control"].includes(section)) {
                    try {
                      setHealth(await api("status"));
                    } catch (e) {
                      setError(e.message);
                    }
                  }
                }}
              />
            </>
          )}
        </>
      )}
      {section === "audit" && (
        <section className="admin-panel">
          <h2>יומן מערכת מוגן</h2>
          <p>
            פעולות מערכת נשמרות ללא עריכה או מחיקה. פעילויות ידניות ניתנות
            לעריכה וארכוב ומותירות תיעוד שינוי.
          </p>
          <label>
            חיפוש ביומן
            <input value={query} onChange={(e) => setQuery(e.target.value)} />
          </label>
          {systemLog
            .filter((a) =>
              JSON.stringify(a).toLowerCase().includes(query.toLowerCase()),
            )
            .map((a) => (
              <p key={a.id}>
                {a.created_at} | {a.actor} | {a.action}
              </p>
            ))}
        </section>
      )}
    </div>
  );
}
function Inventory({ products, rows, run }) {
  const [value, setValue] = useState(null);
  const p = products.find((p) => p.id === value?.productId);
  return (
    <section className="admin-panel">
      <h2>מלאי ותנועות מלאי</h2>
      <p>
        מלאי משויך למוצר או לגרסת מידה וצבע. מחיקת מלאי היא תנועת הפחתה עם סיבה;
        היא אינה מוחקת מוצר.
      </p>
      <button
        className="admin-primary"
        onClick={() =>
          setValue({
            productId: "",
            size: "",
            variantId: "",
            delta: 1,
            reason: "",
          })
        }
      >
        הוספת מלאי / תיקון מלאי
      </button>
      <div className="admin-table-wrap">
        <table>
          <thead>
            <tr>
              <th>מוצר</th>
              <th>מלאי</th>
              <th>פעולות</th>
            </tr>
          </thead>
          <tbody>
            {products
              .filter((p) => !p.archived)
              .map((p) => (
                <tr key={p.id}>
                  <td>
                    {p.he} / {p.sku}
                  </td>
                  <td>{p.stock}</td>
                  <td>
                    <button
                      onClick={() =>
                        setValue({
                          productId: p.id,
                          size: p.sizes[0],
                          variantId: p.variants?.[0]?.id || "",
                          delta: 1,
                          reason: "",
                          revision: p.revision,
                        })
                      }
                    >
                      עריכת מלאי
                    </button>
                    <button
                      onClick={() =>
                        setValue({
                          productId: p.id,
                          size: p.sizes[0],
                          variantId: p.variants?.[0]?.id || "",
                          delta: -1,
                          reason: "",
                          revision: p.revision,
                        })
                      }
                    >
                      הפחתת מלאי
                    </button>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>
      {value && (
        <form
          className="admin-inline-editor"
          onSubmit={(e) => {
            e.preventDefault();
            run(async () => {
              await api("inventory", "POST", value);
              setValue(null);
            });
          }}
        >
          <label>
            מוצר
            <select
              required
              value={value.productId}
              onChange={(e) =>
                setValue({
                  ...value,
                  productId: e.target.value,
                  size: "",
                  variantId: "",
                })
              }
            >
              <option value="">בחרו מוצר</option>
              {products
                .filter((p) => !p.archived)
                .map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.he}
                  </option>
                ))}
            </select>
          </label>
          {p?.variants?.length ? (
            <label>
              גרסת מלאי
              <select
                required
                value={value.variantId}
                onChange={(e) =>
                  setValue({
                    ...value,
                    variantId: e.target.value,
                    size:
                      p.variants.find((v) => v.id === e.target.value)?.size ||
                      "",
                  })
                }
              >
                <option value="">בחירת גרסה</option>
                {p.variants.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.size} / {v.color} / {v.stock}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <label>
              מידה
              <select
                required
                value={value.size}
                onChange={(e) => setValue({ ...value, size: e.target.value })}
              >
                <option value="">בחירת מידה</option>
                {p?.sizes.map((s) => (
                  <option key={s}>{s}</option>
                ))}
              </select>
            </label>
          )}
          <Fields
            fields={[
              text(
                "delta",
                "שינוי כמות: חיובי להוספה, שלילי להפחתה",
                "number",
                true,
              ),
              text("reason", "סיבת תנועת מלאי", "textarea", true),
            ]}
            value={value}
            onChange={setValue}
          />
          <button className="admin-primary">שמירת תנועת מלאי</button>
          <button type="button" onClick={() => setValue(null)}>
            ביטול
          </button>
        </form>
      )}
      <h3>היסטוריית מלאי</h3>
      {rows.map((r) => (
        <p key={r.id}>
          {r.createdAt} |{" "}
          {products.find((p) => p.id === r.productId)?.he || r.productId} |{" "}
          {r.size} | {r.delta} | {r.reason}
        </p>
      ))}
    </section>
  );
}

function PaymentIdentification({ value, onChange }) {
  return (
    <fieldset>
      <legend>פרטי זיהוי תשלום</legend>
      <label>
        אמצעי תשלום
        <select
          value={value.method || "other"}
          onChange={(e) => onChange({ ...value, method: e.target.value })}
        >
          <option value="card">כרטיס אשראי</option>
          <option value="bank-transfer">העברה בנקאית</option>
          <option value="cash">מזומן</option>
          <option value="other">אחר</option>
        </select>
      </label>
      {[
        ["last4", "4 ספרות אחרונות", 4],
        ["cardholderName", "שם בעל הכרטיס", 150],
        ["brand", "מותג כרטיס", 40],
        ["authorizationCode", "מספר אישור", 60],
      ].map(([key, label, max]) => (
        <label key={key}>
          {label}
          <input
            maxLength={max}
            pattern={key === "last4" ? "[0-9]{4}" : undefined}
            inputMode={key === "last4" ? "numeric" : undefined}
            value={value[key] || ""}
            onChange={(e) => onChange({ ...value, [key]: e.target.value })}
          />
        </label>
      ))}
      <label>
        מספר תשלומים
        <input
          type="number"
          min="1"
          max="36"
          value={value.installments ?? 1}
          onChange={(e) =>
            onChange({ ...value, installments: Number(e.target.value) })
          }
        />
      </label>
    </fieldset>
  );
}
