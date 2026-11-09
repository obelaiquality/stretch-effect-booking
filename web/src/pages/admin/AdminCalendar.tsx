import { useEffect, useMemo, useState } from 'react';
import { api } from '../../data/api';
import type { CalendarConnection, Settings, WorkingDay } from '../../domain/types';
import { formatDay, formatDayTime, formatTime } from '../../lib/format';
import './admin.css';
import { AdminFrame, AdminHead, AdminSkeleton, CardSkel, ErrorState, Modal, errText, useLoad, useToast } from './ui';
import { Skel } from '../../components/Skeleton';
import { addDaysKey, dayKey, localToUtcIso, mondayOf, todayKey, WEEKDAYS, WEEKDAYS_SHORT, weekdayOf } from './format';
import { slotRanges, subtractRanges, type Range } from './ranges';

function ConnectionCard() {
  const { data, error, reload } = useLoad<CalendarConnection>(() => api.adminCalendar());
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const toast = useToast();
  const demo = api.mode === 'demo' || data?.mode === 'demo';

  async function connect() {
    setBusy(true);
    try {
      const r = await api.adminConnectCalendar();
      if (r.redirectUrl) {
        window.location.assign(r.redirectUrl);
        return;
      }
      toast.show('Calendar connected.');
      reload();
    } catch (e) {
      toast.show(errText(e), true);
    } finally {
      setBusy(false);
    }
  }
  async function disconnect() {
    setBusy(true);
    try {
      await api.adminDisconnectCalendar();
      toast.show('Calendar disconnected. Existing bookings stay in the system.');
      setConfirm(false);
      reload();
    } catch (e) {
      toast.show(errText(e), true);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="h-gc">
      <h2 className="a-h" id="h-gc">Google Calendar</h2>
      {error && <ErrorState message={error} onRetry={reload} />}
      {!data && !error && <CardSkel lines={4} />}
      {data && (
        <div className="stack">
          <div className="row">
            <span className={'badge ' + (data.connected ? 'badge-ok' : 'badge-warn')}>{data.connected ? 'Connected' : 'Not connected'}</span>
          </div>
          {demo && <p className="small silver measure">Simulated connection. On the live site you sign in with Google once.</p>}
          {data.connected ? (
            <dl className="facts">
              <div><dt>Account</dt><dd>{data.accountEmail ?? 'Unknown'}</dd></div>
              <div><dt>Calendar</dt><dd>{data.calendarId ?? 'Primary'}</dd></div>
              <div><dt>Last sync</dt><dd className="num">{data.lastSyncAt ? formatDayTime(data.lastSyncAt) : 'Not yet'}</dd></div>
            </dl>
          ) : (
            <p className="small muted measure">Connect once. Busy times on your Google Calendar then block the booking page, and each booking is added to your calendar.</p>
          )}
          {data.connected ? (
            <button className="btn btn-ghost btn-block-sm" disabled={busy} onClick={() => setConfirm(true)}>Disconnect</button>
          ) : (
            <button className="btn btn-red btn-block-sm" disabled={busy} onClick={connect}>Connect Google Calendar</button>
          )}
        </div>
      )}
      {confirm && (
        <Modal
          title="Disconnect Google Calendar?"
          onClose={() => setConfirm(false)}
          actions={
            <>
              <button className="btn btn-danger" disabled={busy} onClick={disconnect}>Disconnect</button>
              <button className="btn btn-ghost" onClick={() => setConfirm(false)}>Keep connected</button>
            </>
          }
        >
          <p className="small silver">Your calendar stops blocking times. Clients could then book over your other appointments.</p>
        </Modal>
      )}
      {toast.node}
    </section>
  );
}

function SettingsCard({ settings, onSaved }: { settings: Settings; onSaved: (s: Settings) => void }) {
  const [draft, setDraft] = useState<Settings>(settings);
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  useEffect(() => {
    setDraft(settings);
  }, [settings]);

  const order = [1, 2, 3, 4, 5, 6, 0];
  const setDay = (wd: number, patch: Partial<WorkingDay>) =>
    setDraft((d) => ({ ...d, workingHours: d.workingHours.map((w) => (w.weekday === wd ? { ...w, ...patch } : w)) }));
  const num = (k: keyof Settings) => (e: React.ChangeEvent<HTMLInputElement>) => setDraft((d) => ({ ...d, [k]: Number(e.target.value) }));
  const bad = draft.workingHours.some((w) => w.open && w.start >= w.end) || draft.sessionMinutes < 15 || draft.bufferMinutes < 0;
  const dirty = JSON.stringify(draft) !== JSON.stringify(settings);

  async function save() {
    setBusy(true);
    try {
      const s = await api.adminUpdateSettings({
        sessionMinutes: draft.sessionMinutes,
        bufferMinutes: draft.bufferMinutes,
        minNoticeHours: draft.minNoticeHours,
        cancelFreeHours: draft.cancelFreeHours,
        workingHours: draft.workingHours,
      });
      onSaved(s);
      toast.show('Settings saved.');
    } catch (e) {
      toast.show(errText(e), true);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card" aria-labelledby="h-set">
      <h2 className="a-h" id="h-set">Working hours and rules</h2>
      <div className="hours">
        {order.map((wd) => {
          const w = draft.workingHours.find((x) => x.weekday === wd);
          if (!w) return null;
          return (
            <div key={wd} className="hours-row">
              <label className="check hours-day">
                <input type="checkbox" checked={w.open} onChange={(e) => setDay(wd, { open: e.target.checked })} />
                <span>{WEEKDAYS[wd]}</span>
              </label>
              {w.open ? (
                <div className="hours-times">
                  <input className="input" type="time" step={1800} aria-label={`${WEEKDAYS[wd]} opens`} value={w.start} onChange={(e) => setDay(wd, { start: e.target.value })} />
                  <span className="muted">to</span>
                  <input className="input" type="time" step={1800} aria-label={`${WEEKDAYS[wd]} closes`} value={w.end} onChange={(e) => setDay(wd, { end: e.target.value })} />
                </div>
              ) : (
                <span className="muted small">Closed</span>
              )}
            </div>
          );
        })}
      </div>
      <div className="rules">
        <div className="field"><label htmlFor="s-len">Session length (min)</label><input id="s-len" className="input" type="number" min={15} max={180} step={5} value={draft.sessionMinutes} onChange={num('sessionMinutes')} /></div>
        <div className="field"><label htmlFor="s-buf">Buffer after (min)</label><input id="s-buf" className="input" type="number" min={0} max={60} step={5} value={draft.bufferMinutes} onChange={num('bufferMinutes')} /></div>
        <div className="field"><label htmlFor="s-not">Minimum notice (hours)</label><input id="s-not" className="input" type="number" min={0} max={72} value={draft.minNoticeHours} onChange={num('minNoticeHours')} /></div>
        <div className="field"><label htmlFor="s-can">Free cancellation up to (hours before)</label><input id="s-can" className="input" type="number" min={0} max={72} value={draft.cancelFreeHours} onChange={num('cancelFreeHours')} /></div>
      </div>
      {bad && <p className="error-text">Check the hours: opening must be before closing.</p>}
      <div className="row" style={{ marginTop: 'var(--s4)' }}>
        <button className="btn btn-ghost btn-block-sm" disabled={busy || bad || !dirty} onClick={save}>Save changes</button>
        {dirty && <button className="btn btn-quiet" onClick={() => setDraft(settings)}>Undo</button>}
      </div>
      {toast.node}
    </section>
  );
}

const PX_PER_MIN = 1;

/** True under 768px: the owner calendar shows a day strip and one day instead of seven columns. */
function useNarrow() {
  const q = '(max-width: 767px)';
  const [narrow, setNarrow] = useState(() => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(q).matches : false));
  useEffect(() => {
    if (!window.matchMedia) return;
    const m = window.matchMedia(q);
    const on = () => setNarrow(m.matches);
    on();
    m.addEventListener('change', on);
    return () => m.removeEventListener('change', on);
  }, []);
  return narrow;
}

interface DayBlocks {
  key: string;
  free: Array<{ top: number; h: number; s: number; e: number }>;
  busy: Array<{ top: number; h: number; b: { start: string; end: string; label?: string } }>;
  booked: Array<{ top: number; h: number; b: { id: string; clientName: string; start: string; end: string } }>;
}

function WeekGrid({ settings }: { settings: Settings }) {
  const narrow = useNarrow();
  const [mon, setMon] = useState(mondayOf(todayKey()));
  const [sel, setSel] = useState(() => (weekdayOf(todayKey()) + 6) % 7);
  const sun = addDaysKey(mon, 6);
  const { data, error, reload } = useLoad(
    async () => {
      const [busy, bookings, slots] = await Promise.all([api.adminBusy(mon, sun), api.adminBookings(mon, sun), api.availability(mon, sun)]);
      return { busy, bookings, slots };
    },
    [mon],
  );
  const open = settings.workingHours.filter((w) => w.open);
  const startMin = open.length ? Math.min(...open.map((w) => Number(w.start.slice(0, 2)) * 60 + Number(w.start.slice(3)))) : 6 * 60;
  const endMin = open.length ? Math.max(...open.map((w) => Number(w.end.slice(0, 2)) * 60 + Number(w.end.slice(3)))) : 19 * 60;
  const from = Math.floor(startMin / 60) * 60;
  const to = Math.ceil(endMin / 60) * 60;
  const height = (to - from) * PX_PER_MIN;
  const days = Array.from({ length: 7 }, (_, i) => addDaysKey(mon, i));
  const hours = Array.from({ length: (to - from) / 60 + 1 }, (_, i) => from + i * 60);
  const today = todayKey();

  const cols = useMemo<DayBlocks[] | null>(() => {
    if (!data) return null;
    return days.map((k) => {
      const dayStart = new Date(localToUtcIso(k, '00:00')).getTime();
      const winS = dayStart + from * 60000;
      const winE = dayStart + to * 60000;
      const place = (r: Range) => {
        const a = Math.max(r.s, winS);
        const e = Math.min(r.e, winE);
        return { top: ((a - winS) / 60000) * PX_PER_MIN, h: Math.max(0, ((e - a) / 60000) * PX_PER_MIN) };
      };
      const ms = (iso: string) => new Date(iso).getTime();
      const busyRaw = data.busy.filter((b) => b.source === 'google' && ms(b.end) > dayStart && ms(b.start) < dayStart + 86400000);
      const bookedRaw = data.bookings.filter((b) => (b.status === 'confirmed' || b.status === 'completed') && ms(b.end) > dayStart && ms(b.start) < dayStart + 86400000);
      const blockers: Range[] = [...busyRaw, ...bookedRaw].map((b) => ({ s: ms(b.start), e: ms(b.end) }));
      // Free time = merged possible sessions minus every booked or busy range, so nothing overlaps.
      const free = subtractRanges(slotRanges(data.slots.filter((x) => dayKey(x.start) === k)), blockers);
      return {
        key: k,
        free: free.map((r) => ({ ...place(r), s: r.s, e: r.e })).filter((x) => x.h > 0),
        busy: busyRaw.map((b) => ({ ...place({ s: ms(b.start), e: ms(b.end) }), b })).filter((x) => x.h > 0),
        booked: bookedRaw.map((b) => ({ ...place({ s: ms(b.start), e: ms(b.end) }), b })).filter((x) => x.h > 0),
      };
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, mon, from, to]);

  const shown = narrow ? [sel] : [0, 1, 2, 3, 4, 5, 6];
  const weekLabel = formatDay(localToUtcIso(mon, '12:00'));

  return (
    <section className="card span-3" aria-labelledby="h-wk">
      <div className="row" style={{ marginBottom: 'var(--s4)' }}>
        <h2 className="a-h" id="h-wk" style={{ margin: 0 }}>{narrow ? 'Week' : 'Week of'} <span className="num">{weekLabel}</span></h2>
        <span className="spacer" />
        <button className="btn btn-ghost btn-sm" aria-label="Previous week" onClick={() => setMon(addDaysKey(mon, -7))}>Prev</button>
        <button className="btn btn-ghost btn-sm" onClick={() => { setMon(mondayOf(todayKey())); setSel((weekdayOf(todayKey()) + 6) % 7); }}>This week</button>
        <button className="btn btn-ghost btn-sm" aria-label="Next week" onClick={() => setMon(addDaysKey(mon, 7))}>Next</button>
      </div>
      {narrow && (
        <div className="daystrip" role="group" aria-label="Day">
          {days.map((k, i) => (
            <button key={k} className={'day-btn' + (i === sel ? ' on' : '') + (k === today ? ' today' : '')} aria-pressed={i === sel} aria-label={`${WEEKDAYS[weekdayOf(k)]} ${Number(k.slice(8))}`} onClick={() => setSel(i)}>
              <span>{WEEKDAYS_SHORT[weekdayOf(k)]}</span>
              <strong className="num">{Number(k.slice(8))}</strong>
            </button>
          ))}
        </div>
      )}
      <ul className="legend">
        <li><i className="sw sw-booked" /> Booked</li>
        <li><i className="sw sw-busy" /> Busy (Google Calendar)</li>
        <li><i className="sw sw-free" /> Free to book</li>
      </ul>
      {error && <ErrorState message={error} onRetry={reload} />}
      {!data && !error && <Skel h={narrow ? 320 : 420} />}
      {data && (
        <div className="table-scroll">
          <div className="wk" style={{ ['--wk-h' as string]: `${height}px`, ['--wk-cols' as string]: shown.length }}>
            <div className="wk-axis" aria-hidden="true">
              <div className="wk-headcell" />
              <div className="wk-body">
                {hours.map((m) => (
                  <span key={m} className="wk-hour num" style={{ top: (m - from) * PX_PER_MIN }}>{String(m / 60).padStart(2, '0')}:00</span>
                ))}
              </div>
            </div>
            {shown.map((i) => {
              const k = days[i];
              return (
                <div key={k} className={'wk-col' + (k === today ? ' today' : '')}>
                  <div className="wk-headcell">
                    <span className="label">{WEEKDAYS_SHORT[weekdayOf(k)]}</span>
                    <span className="num">{Number(k.slice(8))}</span>
                  </div>
                  <div className={'wk-body' + (settings.workingHours.find((w) => w.weekday === weekdayOf(k))?.open ? '' : ' closed')}>
                    {hours.map((m) => <i key={m} className="wk-line" style={{ top: (m - from) * PX_PER_MIN }} />)}
                    {cols?.[i].free.map((f) => (
                      <div key={f.s} className="blk blk-free" style={{ top: f.top, height: f.h }} title={`Free ${formatTime(new Date(f.s).toISOString())} to ${formatTime(new Date(f.e).toISOString())}`} />
                    ))}
                    {cols?.[i].busy.map((b, j) => (
                      <div key={'b' + j} className="blk blk-busy" style={{ top: b.top, height: b.h }} title={`${b.b.label ?? 'Busy'} ${formatTime(b.b.start)} to ${formatTime(b.b.end)}`}>
                        <span className="blk-t">{b.b.label ?? 'Busy'}</span>
                        <span className="blk-s num">{formatTime(b.b.start)}</span>
                      </div>
                    ))}
                    {cols?.[i].booked.map((b) => (
                      <div key={b.b.id} className="blk blk-booked" style={{ top: b.top, height: b.h }} title={`${b.b.clientName} ${formatTime(b.b.start)} to ${formatTime(b.b.end)}`}>
                        <span className="blk-t">{b.b.clientName}</span>
                        <span className="blk-s num">{formatTime(b.b.start)}</span>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </section>
  );
}

export default function AdminCalendar() {
  const { data: settings, error, reload } = useLoad(() => api.settings());
  const [saved, setSaved] = useState<Settings | null>(null);
  const s = saved ?? settings;
  return (
    <AdminFrame>
      <AdminHead title="Calendar" />
      {error && <ErrorState message={error} onRetry={reload} />}
      {!s && !error && <AdminSkeleton cards={3} />}
      {s && (
        <div className="admin-grid">
          <ConnectionCard />
          <div className="span-2"><SettingsCard settings={s} onSaved={(x) => { setSaved(x); reload(); }} /></div>
          <WeekGrid key={JSON.stringify(s.workingHours) + s.sessionMinutes + s.bufferMinutes} settings={s} />
        </div>
      )}
    </AdminFrame>
  );
}
