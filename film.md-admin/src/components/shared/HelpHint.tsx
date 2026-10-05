import { useEffect, useId, useRef, useState } from "react";
import { HelpCircleIcon } from "lucide-react";

interface HelpHintProps {
  /** Plain-language explanation. Written for a non-technical operator. */
  children: React.ReactNode;
}

/**
 * Small "?" next to a field label that reveals what the field actually does.
 *
 * Click rather than hover: the campaign form is operated by people who are not
 * advertising specialists, and hover tooltips are unreachable on touch devices.
 */
export function HelpHint({ children }: HelpHintProps) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLSpanElement>(null);
  const id = useId();

  useEffect(() => {
    if (!isOpen) return;

    const close = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setIsOpen(false);
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setIsOpen(false);
    };

    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", escape);
    };
  }, [isOpen]);

  return (
    <span ref={containerRef} className="relative inline-flex align-middle">
      <button
        type="button"
        aria-label="Ce înseamnă acest câmp?"
        aria-expanded={isOpen}
        aria-controls={id}
        onClick={() => setIsOpen((open) => !open)}
        className="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground"
      >
        <HelpCircleIcon className="h-3.5 w-3.5" />
      </button>

      {isOpen && (
        <span
          id={id}
          role="tooltip"
          className="absolute left-5 top-0 z-50 w-64 rounded-lg border border-border bg-popover p-3 text-xs font-normal leading-relaxed text-popover-foreground shadow-lg"
        >
          {children}
        </span>
      )}
    </span>
  );
}
