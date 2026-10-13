import { useEffect, useState } from "react";
import { Pause, Play } from "@phosphor-icons/react";

const defaultSlides = [
  {
    src: "/assets/hero.webp",
    mobile: "/assets/hero-mobile.webp",
    he: "חולצה שחורה ומכנסי שנהב לצד תקריב סריג",
    en: "Black shirt and ivory trousers beside a knit detail",
  },
  {
    src: "/assets/hero-tailoring.webp",
    he: "חליפה שחורה בגזרת ערב לצד תקריב בד",
    en: "Black tailoring beside a fabric detail",
  },
  {
    src: "/assets/hero-knitwear.webp",
    he: "סריג שחור ומכנסי שנהב בצילום אופנה",
    en: "Black knitwear and ivory trousers in a fashion editorial",
  },
];

export function HeroCarousel({ t, images }) {
  const slides =
    images?.length === 3
      ? images.map((src, i) => ({
          src,
          he: "צילום קולקציית SHIMI " + (i + 1),
          en: "SHIMI collection " + (i + 1),
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
      aria-label={t("קולקציית SHIMI", "SHIMI collection")}
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
