"use client";

import {
  forwardRef,
  type ComponentPropsWithoutRef,
  type ElementRef,
  type HTMLAttributes,
} from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";

import { cn } from "../lib/cn.js";
import { CloseIcon } from "./icons.js";

/**
 * Dialog — Radix Dialog styled to the QAYD tokens. The modal primitive behind the chart-of-accounts
 * create / reclassify / deactivate flows (S2-10).
 *
 * Radix carries the accessibility a hand-rolled modal reliably gets wrong: focus is trapped and
 * restored to the trigger on close, the rest of the page is `aria-hidden`, Escape closes, and the
 * title/description are wired to `aria-labelledby`/`aria-describedby`. RTL needs no special handling
 * here because the panel is centred and its one absolutely-positioned child — the close button — uses
 * the logical `end-*` inset, so it mirrors with the document direction.
 */
export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogPortal = DialogPrimitive.Portal;

export const DialogOverlay = forwardRef<
  ElementRef<typeof DialogPrimitive.Overlay>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(function DialogOverlay({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Overlay
      ref={ref}
      className={cn(
        "fixed inset-0 z-50 bg-ink-12/40 backdrop-blur-[2px]",
        className,
      )}
      {...props}
    />
  );
});

export interface DialogContentProps
  extends ComponentPropsWithoutRef<typeof DialogPrimitive.Content> {
  /** Accessible label for the close button — required, because it has no visible text. */
  closeLabel: string;
}

export const DialogContent = forwardRef<
  ElementRef<typeof DialogPrimitive.Content>,
  DialogContentProps
>(function DialogContent({ className, children, closeLabel, ...props }, ref) {
  return (
    <DialogPortal>
      <DialogOverlay />
      <DialogPrimitive.Content
        ref={ref}
        className={cn(
          "fixed start-1/2 top-1/2 z-50 w-full max-w-lg -translate-y-1/2",
          "ltr:-translate-x-1/2 rtl:translate-x-1/2",
          "rounded-lg border border-line bg-surface p-6 shadow-lg",
          "max-h-[calc(100vh-4rem)] overflow-y-auto",
          className,
        )}
        {...props}
      >
        {children}
        <DialogPrimitive.Close
          className={cn(
            "absolute end-4 top-4 rounded-sm p-1 text-muted-foreground",
            "transition-colors hover:bg-ink-hover hover:text-ink-12",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
          )}
        >
          <CloseIcon className="size-4" aria-hidden="true" />
          <span className="sr-only">{closeLabel}</span>
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPortal>
  );
});

export function DialogHeader({
  className,
  ...props
}: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn("flex flex-col gap-1.5 pe-8 text-start", className)}
      {...props}
    />
  );
}

export function DialogFooter({
  className,
  ...props
}: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        "mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end",
        className,
      )}
      {...props}
    />
  );
}

export const DialogTitle = forwardRef<
  ElementRef<typeof DialogPrimitive.Title>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(function DialogTitle({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Title
      ref={ref}
      className={cn("font-display text-text-lg text-ink-12", className)}
      {...props}
    />
  );
});

export const DialogDescription = forwardRef<
  ElementRef<typeof DialogPrimitive.Description>,
  ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(function DialogDescription({ className, ...props }, ref) {
  return (
    <DialogPrimitive.Description
      ref={ref}
      className={cn("text-text-sm text-muted-foreground", className)}
      {...props}
    />
  );
});
