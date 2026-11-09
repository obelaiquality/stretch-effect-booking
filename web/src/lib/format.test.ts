import { describe, expect, it } from 'vitest';
import { formatDay, formatDayTime, formatRand, formatTime, formatTimeRange } from './format';

describe('format', () => {
  it('formats day and time in Cape Town time without a leading zero', () => {
    const y = new Date().getUTCFullYear();
    const iso = `${y}-11-03T15:30:00Z`; // 17:30 SAST
    expect(formatTime(iso)).toBe('17:30');
    expect(formatDay(iso)).toMatch(/^\w{3} 3 Nov$/);
    expect(formatDayTime(iso)).toMatch(/^\w{3} 3 Nov · 17:30$/);
    expect(formatTimeRange(iso, `${y}-11-03T16:30:00Z`)).toBe('17:30 to 18:30');
  });
  it('adds the year only for other years', () => {
    expect(formatDay('2020-01-05T10:00:00Z')).toBe('Sun 5 Jan 2020');
  });
  it('formats rand', () => {
    expect(formatRand(2500)).toBe('R2,500');
    expect(formatRand(550)).toBe('R550');
  });
});
