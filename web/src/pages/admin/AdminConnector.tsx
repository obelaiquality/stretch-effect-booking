import { useCallback, useEffect, useRef, useState } from 'react';
import { api, type McpCallResult } from '../../data/api';
import { TOOLS, type ToolDef } from '../../domain/mcpTools';
import type { AuditEntry, ConnectorGrant } from '../../domain/types';
import './admin.css';
import { AdminFrame, AdminHead, errText, Modal, useLoad, useToast } from './ui';
import { addDaysKey, dayLabel, fmtWhen, todayKey, weekdayOf } from './format';

const SCOPE_LABEL: Record<string, string> = {
  'bookings:read': 'See the diary',
  'bookings:write': 'Book, move and cancel',
  'clients:read': 'Look up clients',
  'credits:write': 'Add or remove sessions',
};

// ------------------------------------------------------------------ chat model
type Msg =
  | { id: number; kind: 'user'; text: string }
  | { id: number; kind: 'assistant'; lines: string[] }
  | {
      id: number;
      kind: 'tool';
      tool: string;
      args: Record<string, unknown>;
      destructive: boolean;
      write: boolean;
      state: 'awaiting' | 'running' | 'done' | 'denied';
      res?: McpCallResult;
    };

const newKey = () => `sim-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
const timeOf = (local: string) => local.slice(11, 16);
const whenOf = (local: string) => `${dayLabel(local.slice(0, 10))} · ${timeOf(local)}`;

interface Chip {
  label: string;
  run: (h: Helpers) => Promise<void>;
}
interface Helpers {
  say: (...lines: string[]) => void;
  call: (tool: string, args: Record<string, unknown>) => Promise<McpCallResult | null>;
}

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

async function findClient(h: Helpers, query: string): Promise<{ client_id: string; name: string } | null> {
  const r = await h.call('search_clients', { query });
  if (!r) return null;
  const hit = (r.result as R | undefined)?.clients?.[0];
  if (!r.ok || !hit) {
    h.say(r.ok ? `I could not find a client matching "${query}".` : `That did not work: ${r.summary}`);
    return null;
  }
  return hit;
}
const failed = (h: Helpers, r: McpCallResult | null) => {
  if (r && !r.ok) h.say(`That did not work: ${r.summary}`);
  else if (!r) h.say('Okay, I have not changed anything.');
};

const CHIPS: Chip[] = [
  {
    label: "Who's booked today?",
    async run(h) {
      const r = await h.call('get_schedule', { date: todayKey() });
      if (!r?.ok) return failed(h, r);
      const res = r.result as R;
      if (!res.bookings.length) return h.say('Nothing is booked today.');
      h.say(
        `You have ${res.bookings.length} ${res.bookings.length === 1 ? 'session' : 'sessions'} today:`,
        ...res.bookings.map((b: R) => `${timeOf(b.start)}  ${b.client_name}`),
        ...(res.busy.length ? [`Google Calendar also has ${res.busy.length} busy ${res.busy.length === 1 ? 'block' : 'blocks'}: ${res.busy.map((b: R) => `${timeOf(b.start)} to ${timeOf(b.end)}`).join(', ')}.`] : []),
      );
    },
  },
  {
    label: 'Find me free slots on Thursday',
    async run(h) {
      const t = todayKey();
      const date = addDaysKey(t, (4 - weekdayOf(t) + 7) % 7);
      const r = await h.call('find_free_slots', { from: date, to: date });
      if (!r?.ok) return failed(h, r);
      const day = (r.result as R).days[0];
      if (!day) return h.say(`There is nothing free on ${dayLabel(date)}.`);
      h.say(`Free start times on ${dayLabel(date)}:`, day.starts.join('  '));
    },
  },
  {
    label: 'How many sessions does Thandi have left?',
    async run(h) {
      const c = await findClient(h, 'Thandi');
      if (!c) return;
      const r = await h.call('get_client', { client_id: c.client_id });
      if (!r?.ok) return failed(h, r);
      const x = r.result as R;
      h.say(
        `${x.name} has ${x.sessions_left} ${x.sessions_left === 1 ? 'session' : 'sessions'} left.`,
        x.upcoming_bookings.length ? `Next booking: ${whenOf(x.upcoming_bookings[0].start)}.` : `${x.name.split(' ')[0]} has nothing booked right now.`,
        ...(x.packs_waiting_for_payment.length ? [`A ${x.packs_waiting_for_payment[0].sessions}-session pack is waiting for payment.`] : []),
      );
    },
  },
  {
    label: 'Thandi paid for a 5-pack, EFT ref TSE-4471. Load it.',
    async run(h) {
      const c = await findClient(h, 'Thandi');
      if (!c) return;
      const r = await h.call('add_session_credits', {
        client_id: c.client_id,
        sessions: 5,
        reason: 'EFT payment received for a 5-session pack',
        payment_reference: 'TSE-4471',
        idempotency_key: newKey(),
      });
      if (!r?.ok) return failed(h, r);
      const x = r.result as R;
      h.say(`Done. I added 5 sessions to ${x.name} against reference TSE-4471. ${x.name.split(' ')[0]} now has ${x.sessions_left}.`);
    },
  },
  {
    label: "Cancel Pieter's next booking and refund the session",
    async run(h) {
      const c = await findClient(h, 'Pieter');
      if (!c) return;
      const g = await h.call('get_client', { client_id: c.client_id });
      if (!g?.ok) return failed(h, g);
      const next = (g.result as R).upcoming_bookings[0];
      if (!next) return h.say(`${c.name} has no upcoming bookings to cancel.`);
      const r = await h.call('cancel_booking', { booking_id: next.booking_id, refund_credit: true, reason: 'Cancelled by the owner via ChatGPT, session refunded' });
      if (!r?.ok) return failed(h, r);
      const x = r.result as R;
      h.say(`I cancelled ${x.client_name} on ${whenOf(x.start)} and gave the session back. ${x.client_name.split(' ')[0]} now has ${x.sessions_left}.`);
    },
  },
  {
    label: 'How was this week?',
    async run(h) {
      const r = await h.call('get_business_summary', { period: 'week' });
      if (!r?.ok) return failed(h, r);
      const x = r.result as R;
      h.say(
        `Over the last 7 days: ${x.sessions_booked} sessions booked, ${x.sessions_completed} completed and ${x.sessions_cancelled} cancelled.`,
        `You sold ${x.sessions_sold} sessions and gained ${x.new_clients} new ${x.new_clients === 1 ? 'client' : 'clients'}. There are about ${x.free_hours_ahead} free hours in the week ahead.`,
      );
    },
  },
];

// ------------------------------------------------------------------ sample args for the console
function sampleArgs(t: ToolDef): string {
  const o: Record<string, unknown> = {};
  const today = todayKey();
  for (const k of t.inputSchema.required) {
    const d = t.inputSchema.properties[k];
    if (k === 'date' || k === 'from' || k === 'to') o[k] = today;
    else if (k === 'start' || k === 'new_start') o[k] = `${addDaysKey(today, 2)}T09:00`;
    else if (k === 'idempotency_key') o[k] = newKey();
    else if (d.enum) o[k] = d.enum[0];
    else if (d.type === 'integer') o[k] = 1;
    else if (d.type === 'boolean') o[k] = true;
    else if (k === 'client_id') o[k] = 'u_thandi';
    else if (k === 'query') o[k] = 'Thandi';
    else if (k === 'reason') o[k] = 'Owner correction';
    else o[k] = '';
  }
  return JSON.stringify(o, null, 2);
}

const pretty = (v: unknown) => JSON.stringify(v, null, 2);

// ------------------------------------------------------------------ page
export default function AdminConnector() {
  const demo = typeof api.demoCallTool === 'function';
  const grants = useLoad(() => api.adminGrants());
  const audit = useLoad(() => api.adminAudit(30));
  const toast = useToast();
  const [revoking, setRevoking] = useState<ConnectorGrant | null>(null);
  const [resetting, setResetting] = useState(false);

  // chat
  const [msgs, setMsgs] = useState<Msg[]>([]);
  const [running, setRunning] = useState(false);
  const idRef = useRef(0);
  const resolvers = useRef(new Map<number, (ok: boolean) => void>());
  const endRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }, [msgs]);

  const patch = useCallback((id: number, p: Partial<Extract<Msg, { kind: 'tool' }>>) => {
    setMsgs((m) => m.map((x) => (x.id === id && x.kind === 'tool' ? { ...x, ...p } : x)));
  }, []);

  const helpers: Helpers = {
    say: (...lines) => setMsgs((m) => [...m, { id: ++idRef.current, kind: 'assistant', lines }]),
    async call(tool, args) {
      const def = TOOLS.find((t) => t.name === tool);
      const id = ++idRef.current;
      const write = !!def?.write;
      const destructive = !!def?.annotations.destructiveHint;
      setMsgs((m) => [...m, { id, kind: 'tool', tool, args, write, destructive, state: write ? 'awaiting' : 'running' }]);
      if (write) {
        const allowed = await new Promise<boolean>((res) => resolvers.current.set(id, res));
        if (!allowed) {
          patch(id, { state: 'denied' });
          return null;
        }
        patch(id, { state: 'running' });
      }
      await new Promise((r) => setTimeout(r, 350));
      const res = await api.demoCallTool!(tool, args);
      patch(id, { state: 'done', res });
      audit.reload();
      grants.reload();
      return res;
    },
  };

  async function runChip(c: Chip) {
    if (running) return;
    setRunning(true);
    setMsgs((m) => [...m, { id: ++idRef.current, kind: 'user', text: c.label }]);
    try {
      await c.run(helpers);
    } catch (e) {
      helpers.say(`Something went wrong: ${errText(e)}`);
    } finally {
      setRunning(false);
    }
  }
  const decide = (id: number, ok: boolean) => {
    resolvers.current.get(id)?.(ok);
    resolvers.current.delete(id);
  };

  // console
  const [toolName, setToolName] = useState(TOOLS[0].name);
  const [argText, setArgText] = useState(() => sampleArgs(TOOLS[0]));
  const [consoleOut, setConsoleOut] = useState<string | null>(null);
  const [consoleBusy, setConsoleBusy] = useState(false);
  const tool = TOOLS.find((t) => t.name === toolName)!;

  async function runConsole() {
    let args: Record<string, unknown>;
    try {
      args = JSON.parse(argText || '{}');
    } catch {
      setConsoleOut('Those arguments are not valid JSON.');
      return;
    }
    setConsoleBusy(true);
    try {
      const res = await api.demoCallTool!(toolName, args);
      setConsoleOut(pretty(res));
      audit.reload();
      grants.reload();
    } catch (e) {
      setConsoleOut(errText(e));
    } finally {
      setConsoleBusy(false);
    }
  }

  async function revoke() {
    if (!revoking) return;
    try {
      await api.adminRevokeGrant(revoking.id);
      toast.show('Revoked. ChatGPT can no longer read or change anything.');
      setRevoking(null);
      grants.reload();
      audit.reload();
    } catch (e) {
      toast.show(errText(e), true);
    }
  }

  const list = grants.data ?? [];
  const active = list.filter((g) => !g.revokedAt);

  return (
    <AdminFrame>
      <AdminHead eyebrow="ChatGPT connector" title={<>Run the studio from <span className="red">ChatGPT</span></>} />
      <p className="lede silver">
        Connect your own ChatGPT to the studio. Ask in plain words who is booked, find a free slot, load a pack someone has paid for or cancel a booking. ChatGPT asks you to confirm before it cancels a booking or removes sessions. Every call is written to the log below, and you can cut the connection at any time.
      </p>

      <div className="admin-grid">
        {/* (a) status */}
        <section className="card card-red-top span-3" aria-labelledby="h-status">
          <h2 className="a-h" id="h-status">Connection status</h2>
          {grants.error && <p className="error-text">{grants.error}</p>}
          {grants.data && list.length === 0 && <p className="muted">Nothing is connected yet. Follow the steps further down.</p>}
          <ul className="a-list">
            {list.map((g) => (
              <li key={g.id} className="a-li wrap-li grant">
                <div>
                  <div className="row" style={{ gap: 8 }}>
                    <strong>{g.clientName}</strong>
                    <span className={'badge ' + (g.revokedAt ? 'badge-red' : 'badge-ok')}>{g.revokedAt ? 'Revoked' : 'Connected'}</span>
                  </div>
                  <div className="chips" style={{ margin: '8px 0' }}>
                    {g.scopes.map((s) => (
                      <span key={s} className="badge" title={s}>{SCOPE_LABEL[s] ?? s}</span>
                    ))}
                  </div>
                  <div className="muted small num">
                    Connected {fmtWhen(g.createdAt)} · {g.revokedAt ? `revoked ${fmtWhen(g.revokedAt)}` : g.lastUsedAt ? `last used ${fmtWhen(g.lastUsedAt)}` : 'never used'}
                  </div>
                </div>
                {!g.revokedAt && (
                  <button className="btn btn-ghost btn-sm" onClick={() => setRevoking(g)}>Revoke</button>
                )}
              </li>
            ))}
          </ul>
          <p className="small silver kill">
            <strong>Kill switch.</strong> Revoke ends access straight away. The next request from that connection is refused, and its tokens stop working. You can connect again later with your password and 2FA.
          </p>
          {demo && active.length === 0 && list.length > 0 && (
            <button className="btn btn-quiet btn-sm" onClick={() => setResetting(true)}>Reset the demo to try again</button>
          )}
        </section>

        {/* (b) simulation */}
        {demo ? (
          <section className="card span-3" aria-labelledby="h-sim">
            <div className="eyebrow">Simulation</div>
            <h2 className="a-h" id="h-sim">Your ChatGPT (simulation)</h2>
            <p className="small muted">A scripted preview of what the real thing will feel like. Each prompt runs the same tools the live connector offers, on the demo data.</p>
            <div className="chips prompt-chips" role="group" aria-label="Example prompts">
              {CHIPS.map((c) => (
                <button key={c.label} className="chip" disabled={running} onClick={() => runChip(c)}>{c.label}</button>
              ))}
            </div>
            <div className="chat" role="log" aria-live="polite" aria-label="Simulated conversation">
              {msgs.length === 0 && <p className="muted small chat-empty">Pick a prompt above to start.</p>}
              {msgs.map((m) =>
                m.kind === 'user' ? (
                  <div key={m.id} className="bubble bubble-user">{m.text}</div>
                ) : m.kind === 'assistant' ? (
                  <div key={m.id} className="bubble bubble-ai">
                    {m.lines.map((l, i) => <p key={i}>{l}</p>)}
                  </div>
                ) : (
                  <div key={m.id} className={'toolblock' + (m.destructive ? ' destructive' : '')}>
                    <div className="tb-head">
                      <span className="tb-name">{m.state === 'running' ? 'Running ' : 'Used '}{m.tool}</span>
                      {m.write && <span className={'badge ' + (m.destructive ? 'badge-red' : 'badge-warn')}>{m.destructive ? 'Changes data, destructive' : 'Changes data'}</span>}
                    </div>
                    <pre className="code">{pretty(m.args)}</pre>
                    {m.state === 'awaiting' && (
                      <div className="approval" role="group" aria-label={`Approve ${m.tool}`}>
                        <p className="small"><strong>ChatGPT wants to run {m.tool}.</strong> Nothing happens until you allow it.</p>
                        <div className="row">
                          <button className={'btn btn-sm ' + (m.destructive ? 'btn-red' : 'btn-ghost')} onClick={() => decide(m.id, true)}>Allow</button>
                          <button className="btn btn-sm btn-ghost" onClick={() => decide(m.id, false)}>Deny</button>
                        </div>
                      </div>
                    )}
                    {m.state === 'denied' && <p className="small muted">Denied. Nothing was changed.</p>}
                    {m.state === 'done' && m.res && (
                      <>
                        <div className="tb-sub eyebrow">{m.res.ok ? 'Result' : 'Error'}</div>
                        <pre className={'code' + (m.res.ok ? '' : ' code-bad')}>{pretty(m.res)}</pre>
                      </>
                    )}
                  </div>
                ),
              )}
              <div ref={endRef} />
            </div>
            {msgs.length > 0 && <button className="btn btn-quiet btn-sm" disabled={running} onClick={() => setMsgs([])}>Clear conversation</button>}
          </section>
        ) : (
          <section className="card span-3">
            <h2 className="a-h">Simulation</h2>
            <p className="muted small">The scripted ChatGPT preview only runs in the demo. On the live site, use your real ChatGPT.</p>
          </section>
        )}

        {/* (c) console */}
        {demo && (
          <section className="card span-3" aria-labelledby="h-con">
            <h2 className="a-h" id="h-con">Tool console</h2>
            <p className="small muted">For power users. Pick a tool, edit the arguments and run it exactly as the connector would. Changes made here apply immediately, without the approval step.</p>
            <div className="console">
              <div className="stack">
                <div className="field">
                  <label htmlFor="tool">Tool</label>
                  <select id="tool" className="select" value={toolName} onChange={(e) => {
                    setToolName(e.target.value);
                    setArgText(sampleArgs(TOOLS.find((t) => t.name === e.target.value)!));
                    setConsoleOut(null);
                  }}>
                    {TOOLS.map((t) => <option key={t.name} value={t.name}>{t.name}{t.write ? ' (changes data)' : ''}</option>)}
                  </select>
                </div>
                <p className="small silver">{tool.description}</p>
                <p className="tiny muted">Needs the "{tool.scope}" permission. Required: {tool.inputSchema.required.join(', ') || 'nothing'}.</p>
                <div className="field">
                  <label htmlFor="args">Arguments (JSON)</label>
                  <textarea id="args" className="textarea mono" rows={9} spellCheck={false} value={argText} onChange={(e) => setArgText(e.target.value)} />
                </div>
                <div className="row">
                  <button className="btn btn-red" disabled={consoleBusy} onClick={runConsole}>Run tool</button>
                  <button className="btn btn-quiet" onClick={() => setArgText(sampleArgs(tool))}>Reset arguments</button>
                </div>
              </div>
              <div>
                <div className="eyebrow">Result</div>
                <pre className="code console-out" aria-live="polite">{consoleOut ?? 'Run a tool to see the result.'}</pre>
              </div>
            </div>
          </section>
        )}

        {/* (d) audit */}
        <section className="card span-3" aria-labelledby="h-aud">
          <h2 className="a-h" id="h-aud">Audit log</h2>
          {audit.error && <p className="error-text">{audit.error}</p>}
          {audit.data && audit.data.length === 0 && <p className="muted">No calls yet.</p>}
          {audit.data && audit.data.length > 0 && (
            <div className="table-scroll">
              <table className="table">
                <thead><tr><th>When</th><th>Tool</th><th>Status</th><th>What happened</th></tr></thead>
                <tbody>
                  {audit.data.map((a: AuditEntry) => (
                    <tr key={a.id}>
                      <td className="num nowrap">{fmtWhen(a.at)}</td>
                      <td><code>{a.tool}</code></td>
                      <td><span className={'badge ' + (a.status === 'ok' ? 'badge-ok' : a.status === 'replayed' ? '' : 'badge-red')}>{a.status.replace('_', ' ')}</span></td>
                      <td>{a.summary}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        {/* (e) connect for real */}
        <section className="card span-2" aria-labelledby="h-conn">
          <h2 className="a-h" id="h-conn">Connect your real ChatGPT</h2>
          <ol className="steps">
            <li><strong>Check your ChatGPT plan.</strong> Custom connectors need a paid plan. Business, Enterprise and Edu are confirmed; whether Plus and Pro allow changes (not only reading) is reported differently by different sources, so test on your own account first.</li>
            <li><strong>Turn on developer mode.</strong> In ChatGPT open Settings, then Apps, then Advanced settings, then Developer mode. OpenAI has moved this around: it may be under Settings, Connectors, Advanced, or on the Plugins page with "Add custom MCP server".</li>
            <li><strong>Add the connector.</strong> Choose to add a custom connector and paste your studio address: <code>https://your-domain.co.za/mcp</code>.</li>
            <li><strong>Sign in as the owner.</strong> A studio page opens. Enter your password and your 6-digit 2FA code.</li>
            <li><strong>Choose what ChatGPT may do.</strong> Tick only the permissions you want: see the diary, look up clients, book and cancel, add sessions. You can start read-only.</li>
            <li><strong>Try it.</strong> Ask "Who's booked today?" Approve any change ChatGPT asks to make.</li>
          </ol>
          <p className="hint">The live connector needs the studio hosted on a real domain with the PHP backend. See <a href="#/go-live">What it takes to go live</a>.</p>
        </section>

        <section className="card" aria-labelledby="h-sec">
          <h2 className="a-h" id="h-sec">Security</h2>
          <ul className="ticks">
            <li>Only you can approve a connection, with password and 2FA.</li>
            <li>ChatGPT gets only the permissions you tick.</li>
            <li>Access passes expire after an hour and renew on their own; revoke ends them at once.</li>
            <li>ChatGPT can change at most 40 sessions a day, 20 per call, and every change needs a reason.</li>
            <li>The same action sent twice only happens once.</li>
            <li>Limits of 60 calls a minute and 20 changes an hour.</li>
            <li>Every call is logged here: tool, result and time.</li>
            <li>Passwords, tokens and 2FA secrets are never shared with ChatGPT.</li>
            <li>Client names are treated as data, never as instructions.</li>
          </ul>
        </section>
      </div>

      {revoking && (
        <Modal
          title={`Revoke ${revoking.clientName}?`}
          onClose={() => setRevoking(null)}
          actions={
            <>
              <button className="btn btn-red" onClick={revoke}>Revoke access</button>
              <button className="btn btn-ghost" onClick={() => setRevoking(null)}>Keep connected</button>
            </>
          }
        >
          <p className="small silver">ChatGPT will immediately lose access to your diary, clients and sessions. Past changes stay as they are in the log.</p>
        </Modal>
      )}
      {resetting && (
        <Modal
          title="Reset the demo?"
          onClose={() => setResetting(false)}
          actions={
            <>
              <button className="btn btn-red" onClick={async () => { await api.demoReset?.(); window.location.hash = '#/login'; window.location.reload(); }}>Reset demo data</button>
              <button className="btn btn-ghost" onClick={() => setResetting(false)}>Cancel</button>
            </>
          }
        >
          <p className="small silver">This puts all demo data back to the starting state and signs you out.</p>
        </Modal>
      )}
      {toast.node}
    </AdminFrame>
  );
}
