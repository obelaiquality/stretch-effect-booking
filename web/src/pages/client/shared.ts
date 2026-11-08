import type { LedgerReason } from '../../domain/types';

export const REASON_LABEL: Record<LedgerReason, string> = {
  purchase: 'Sessions loaded',
  booking: 'Session booked',
  cancel_refund: 'Session refunded',
  owner_adjust: 'Adjusted by Ivan',
  chatgpt_adjust: 'Adjusted by Ivan',
  welcome_offer: 'Welcome offer',
};

export const money = (n: number) => `R${n.toLocaleString('en-ZA').replace(/\s/g, ',')}`;

export const firstName = (name: string) => name.split(' ')[0] ?? name;
