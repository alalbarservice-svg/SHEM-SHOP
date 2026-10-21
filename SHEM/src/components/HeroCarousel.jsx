import { useEffect, useState } from "react";
import { Pause, Play } from "@phosphor-icons/react";

const defaultSlides = [
  {
    src: "/assets/hero-look1.webp",
    mobile: "/assets/hero-look1-mobile.webp",
    he: "סריג בז' עם תקריב שרוול ומכנסיים שחורים בקולונדה",
    en: "Beige knit with a sleeve detail and black trousers in a colonnade",
  },
  {
    src: "/assets/hero-look2.webp",
    mobile: "/assets/hero-look2-mobile.webp",
    he: "חולצת טאופ ומכנסי פשתן לבנים לצד תקריב כיס",
    en: "Taupe top and white linen trousers beside a pocket detail",
  },
  {
    src: "/assets/hero-look3.webp",
    mobile: "/assets/hero-look3-mobile.webp",
    he: "סריג כבלים שנהב ומכנסיים אפורים לצד תקריב סריג",
    en: "Ivory cable knit and grey trousers beside a knit detail",
  },
];

export function HeroCarousel({ t, images }) {
  const slides =
    images?.length === 3
      ? images.map((src, i) => ({
          src,
          he: "צילום קולקציית SHEM " + (i + 1),
          en: "SHEM collection " + (i + 1),
        }))
      : defaultSlides;
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  const [hidden, setHidden] = useState(document.hidden);
  const [reduced, setReduced] = useState(
    () => matchMedia("(prefers-reduced-motion: reduce)").matches,
  );
  const [ready, setReady] = useState({ 0: true });
  useEffect(() => {
    const media = matchMedia("(prefers-reduced-motion: reduce)");
    const motion = () => setReduced(media.matches);
    const visibility = () => setHidden(document.hidden);
    media.addEventListener("change", motion);
    document.addEventListener("visibilitychange", visibility);
    return () => {
      media.removeEventListener("change", motion);
      document.removeEventListener("visibilitychange", visibility);
    };
  }, []);
  useEffect(() => {
    if (paused || reduced || hidden) return;
    const timer = setInterval(() => {
      const next = (active + 1) % slides.length;
      if (ready[next]) setActive(next);
    }, 5000);
    return () => clearInterval(timer);
  }, [active, paused, reduced, hidden, ready]);
  return (
    <div
      className="hero-carousel"
      role="region"
      aria-roledescription={t("מצגת תמונות", "carousel")}
      aria-label={t("קולקציית SHEM", "SHEM collection")}
      data-active-slide={active + 1}
    >
      <div className="hero-slides" aria-live="off">
        {slides.map((slide, i) => (
          <picture
            key={slide.src}
            className={`hero-media hero-slide${i === active ? " is-active" : ""}`}
            aria-hidden={i !== active}
          >
            {slide.mobile && (
              <source media="(max-width: 760px)" srcSet={slide.mobile} />
            )}
            <img
              className={`hero-image${slide.mobile ? "" : " hero-portrait-crop"}`}
              src={slide.src}
              alt={i === active ? t(slide.he, slide.en) : ""}
              fetchPriority={i === 0 ? "high" : "low"}
              decoding="async"
              onLoad={() => setReady((old) => ({ ...old, [i]: true }))}
            />
          </picture>
        ))}
      </div>
      <div className="hero-carousel-controls">
        <div
          className="hero-dots"
          role="group"
          aria-label={t("בחירת תמונה", "Choose an image")}
        >
          {slides.map((slide, i) => (
            <button
              key={slide.src}
              className="hero-dot"
              aria-label={t(`תמונה ${i + 1} מתוך 3`, `Image ${i + 1} of 3`)}
              aria-pressed={i === active}
              disabled={!ready[i]}
              onClick={() => setActive(i)}
            >
              <span />
            </button>
          ))}
        </div>
        {!reduced && (
          <button
            className="hero-playback"
            aria-label={
              paused
                ? t("הפעלת תחלופת התמונות", "Resume slideshow")
                : t("עצירת תחלופת התמונות", "Pause slideshow")
            }
            onClick={() => setPaused((value) => !value)}
          >
            {paused ? (
              <Play size={15} weight="fill" />
            ) : (
              <Pause size={15} weight="fill" />
            )}
          </button>
        )}
      </div>
    </div>
  );
}
