import { useCallback, useEffect, useState } from 'react';
import api from '../api/axios';

/**
 * Whether the business currently has a cash register open.
 *
 * Both header modals report figures for the day in progress, and a day nobody
 * has started has nothing to report: the numbers would read as a real zero
 * rather than as "the shift has not begun". So they ask this first and only
 * fetch once the answer is yes.
 *
 * `enabled` is what keeps the check off the critical path: the hook is mounted
 * with the header but stays idle until a modal actually opens, so the topbar
 * costs no extra request on every navigation.
 */
export default function useOpenRegisterStatus(enabled) {
  const [status, setStatus] = useState(null);
  const [checking, setChecking] = useState(false);

  const check = useCallback(async () => {
    setChecking(true);
    try {
      const res = await api.get('/cash-registers/status');
      setStatus(res.data.data);
      return res.data.data;
    } catch {
      /*
       * A failed check is NOT treated as "closed". Reporting "open a register
       * first" when the real problem was a dropped request would send an
       * operator to open a drawer they already have open. Null means unknown,
       * and the caller shows a load failure instead.
       */
      setStatus(null);
      return null;
    } finally {
      setChecking(false);
    }
  }, []);

  useEffect(() => {
    if (enabled) check();
  }, [enabled, check]);

  return { status, checking, check };
}
