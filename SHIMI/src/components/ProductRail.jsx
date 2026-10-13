import { useRef } from "react";
import { ArrowLeft, ArrowRight } from "@phosphor-icons/react";
export function ProductRail({ children, label, compact = false, he = true }) {
  const ref = useRef(null);
  const move = (direction) =>
    ref.current?.scrollBy({
      left: direction * ref.current.clientWidth * 0.72,
      behavior: matchMedia("(prefers-reduced-motion: reduce)").matches
        ? "instant"
        : "smooth",
    });
  return (
    <div className={compact ? "rail-wrap compact" : "rail-wrap"}>
      <div className="rail-controls">
        <button
          aria-label={`${label} - ${he ? "הקודם" : "previous"}`}
          onClick={() => move(-1)}
        >
          <ArrowLeft />
        </button>
        <button
          aria-label={`${label} - ${he ? "הבא" : "next"}`}
          onClick={() => move(1)}
        >
          <ArrowRight />
        </button>
      </div>
      <div
        className="product-rail"
        ref={ref}
        tabIndex={0}
        role="region"
        aria-label={label}
      >
        {children}
      </div>
    </div>
  );
}
