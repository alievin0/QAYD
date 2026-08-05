import type { AccountTreeNode } from "@qayd/types";

/**
 * Presentation helpers for the chart-of-accounts tree (S2-10).
 *
 * Everything here is a RENDERING concern — which rows are visible at the current expansion state, which
 * survive the search box, how deep each one sits. No accounting rule lives in this file and none may:
 * whether an account can be renumbered, reclassified, or posted to is decided by the API, and the screen
 * only ever reports what the server said.
 *
 * In particular, `hasChildren` is deliberately NOT treated as "postable". CHART_OF_ACCOUNTS.md is
 * explicit that posting eligibility is its own flag and that a leaf account can still be non-postable.
 * Inferring one from the other would be inventing a business rule in the browser.
 */

/** One row as the tree renders it: the account, its depth, and whether it can be expanded. */
export interface AccountRow {
  account: AccountTreeNode;
  depth: number;
  hasChildren: boolean;
  isExpanded: boolean;
}

/** Total accounts in a tree, counting every level. */
export function countAccounts(nodes: AccountTreeNode[]): number {
  return nodes.reduce(
    (total, node) => total + 1 + countAccounts(node.children),
    0,
  );
}

/** Every account id in the tree — what "expand all" expands to. */
export function collectIds(nodes: AccountTreeNode[]): number[] {
  return nodes.flatMap((node) => [node.id, ...collectIds(node.children)]);
}

/** The ids of every node that has children — the only ones expansion means anything for. */
export function collectParentIds(nodes: AccountTreeNode[]): number[] {
  return nodes.flatMap((node) =>
    node.children.length > 0
      ? [node.id, ...collectParentIds(node.children)]
      : [],
  );
}

/**
 * Flatten the tree into the rows currently on screen: a node's children appear only while the node is
 * expanded. Depth is carried on the row rather than nested in the markup, so the table stays one flat
 * `<tbody>` — which is what keeps the columns aligned across levels and lets a screen reader read the
 * whole thing as a table instead of a pile of nested grids.
 */
export function flattenVisible(
  nodes: AccountTreeNode[],
  expanded: ReadonlySet<number>,
  depth = 0,
): AccountRow[] {
  return nodes.flatMap((node) => {
    const hasChildren = node.children.length > 0;
    const isExpanded = hasChildren && expanded.has(node.id);
    const row: AccountRow = { account: node, depth, hasChildren, isExpanded };

    return isExpanded
      ? [row, ...flattenVisible(node.children, expanded, depth + 1)]
      : [row];
  });
}

/** Flatten every node regardless of expansion — the flat "list" view, in the order the API returned. */
export function flattenAll(nodes: AccountTreeNode[], depth = 0): AccountRow[] {
  return nodes.flatMap((node) => [
    {
      account: node,
      depth,
      hasChildren: node.children.length > 0,
      isExpanded: false,
    },
    ...flattenAll(node.children, depth + 1),
  ]);
}

/**
 * Keep a node when it matches, or when any descendant does — so searching for a leaf still shows the
 * branch it lives on rather than tearing it out of context. Matching is case-insensitive across the code
 * and BOTH names, because a bilingual chart gets searched in whichever language the user is thinking in.
 */
export function filterTree(
  nodes: AccountTreeNode[],
  query: string,
): AccountTreeNode[] {
  const needle = query.trim().toLowerCase();
  if (needle === "") return nodes;

  return nodes.flatMap((node) => {
    const children = filterTree(node.children, query);
    const matches =
      node.code.toLowerCase().includes(needle) ||
      node.name_en.toLowerCase().includes(needle) ||
      node.name_ar.toLowerCase().includes(needle);

    if (!matches && children.length === 0) return [];
    return [{ ...node, children }];
  });
}
