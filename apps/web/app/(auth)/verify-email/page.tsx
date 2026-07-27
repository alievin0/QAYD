import { VerifyEmailClient } from "./verify-email-client";

/**
 * `/verify-email` — the landing a mailed verification link resolves to (S1-15). It reads the signed-link
 * params server-side and hands them to the client island, which posts them once to the BFF. Public: a
 * just-registered user is not signed in yet, so no session guard runs here.
 */
export default async function VerifyEmailPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const first = (value: string | string[] | undefined): string | null =>
    Array.isArray(value) ? (value[0] ?? null) : (value ?? null);

  return (
    <VerifyEmailClient
      id={first(params.id)}
      hash={first(params.hash)}
      expires={first(params.expires)}
      signature={first(params.signature)}
    />
  );
}
