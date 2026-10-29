import { reactive } from 'vue'
import { get } from '@/lib/api'

/**
 * "Has anything changed?" probe shared by admin pages. A cashier's sale bumps last_sale_id;
 * pages watch `version` and reload their own data, so nobody needs to press refresh.
 */
export const pulse = reactive({
  last_sale_id: 0,
  last_movement_id: 0,
  unread_notifications: 0,
  version: 0,
  async refresh() {
    try {
      const r = await get('/admin/reports/pulse')
      if (r.last_sale_id !== this.last_sale_id || r.last_movement_id !== this.last_movement_id) {
        if (this.last_sale_id || this.last_movement_id) this.version++
        this.last_sale_id = r.last_sale_id
        this.last_movement_id = r.last_movement_id
      }
      this.unread_notifications = r.unread_notifications
    } catch {
      /* offline: keep last values */
    }
  },
})
