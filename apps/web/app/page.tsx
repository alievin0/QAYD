export default function Home() {
  return (
    <main>
      <h1>QAYD</h1>
      <p>The AI Financial Operating System.</p>
      <p>
        This is the QAYD web app — the browser-facing surface of the platform.
        It holds no database credentials; it will consume the Laravel API at{" "}
        <code>/api/v1</code> through the typed SDK in a later story.
      </p>
      <p>
        Service health is exposed at <a href="/health">/health</a>.
      </p>
    </main>
  );
}
