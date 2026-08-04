import type { ReactNode } from "react";

import { AccountingTabs } from "./accounting-tabs";

/**
 * The `accounting/` section layout (S2-10): the module sub-nav wrapped around every accounting route.
 * The tabs live here so adding a section is one entry rather than a nav copy in each page.
 */
export default function AccountingLayout({
  children,
}: {
  children: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-6">
      <AccountingTabs />
      {children}
    </div>
  );
}
