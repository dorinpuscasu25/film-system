import React from "react";
import { XIcon } from "lucide-react";
import { cn } from "../../lib/utils";
import { Button } from "../ui/button";

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  size?: "sm" | "md" | "lg" | "xl" | "full";
}

export function Modal({
  isOpen,
  onClose,
  title,
  children,
  footer,
  size = "md",
}: ModalProps) {
  if (!isOpen) {
    return null;
  }

  const sizeClasses = {
    sm: "max-w-md",
    md: "max-w-lg",
    lg: "max-w-2xl",
    xl: "max-w-4xl",
    full: "max-w-[95vw] h-[95vh]",
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" onClick={onClose} />

      <div
        className={cn(
          "relative z-10 flex w-full flex-col overflow-hidden rounded-xl border bg-background",
          sizeClasses[size],
          size === "full" ? "h-[95vh]" : "max-h-[90vh]",
        )}
      >
        <div className="flex items-center justify-between border-b px-6 py-4">
          <div>
            <h3 className="text-lg font-semibold">{title}</h3>
          </div>

          <Button variant="ghost" size="icon" onClick={onClose}>
            <XIcon className="h-4 w-4" />
          </Button>
        </div>

        <div className="admin-scrollbar flex-1 overflow-y-auto px-6 py-5">{children}</div>

        {footer ? <div className="flex flex-wrap justify-end gap-3 border-t px-6 py-4">{footer}</div> : null}
      </div>
    </div>
  );
}
