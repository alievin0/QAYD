import { forwardRef, type InputHTMLAttributes } from "react";

import { cn } from "../lib/cn.js";

/**
 * Input — 6px corners, `ink-7` border, 16px text (`text-md`, which also avoids iOS Safari zoom-on-focus).
 * Placeholder uses `muted-foreground`; the focus ring is the brass `ring` with an offset.
 */
export type InputProps = InputHTMLAttributes<HTMLInputElement>;

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, type, ...props },
  ref,
) {
  return (
    <input
      ref={ref}
      type={type}
      className={cn(
        "flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-md text-foreground ring-offset-background transition-colors",
        "placeholder:text-muted-foreground",
        "file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        "disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
      {...props}
    />
  );
});
