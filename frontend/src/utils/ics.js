// Build and download an .ics calendar file for a booked session.
// Pure client-side — no backend needed (Should-Have §6.2.3).

function toIcsDate(date) {
  // YYYYMMDDTHHMMSSZ in UTC
  return date.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z'
}

export function buildBookingIcs(booking) {
  const start = new Date(booking.booking_date)
  const end = new Date(start.getTime() + (booking.duration || 1) * 60 * 60 * 1000)
  const otherName = booking.tutor_name || booking.learner_name || 'SkillSwap session'

  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//SkillSwap//Tutoring//EN',
    'BEGIN:VEVENT',
    `UID:booking-${booking.booking_id}@skillswap`,
    `DTSTAMP:${toIcsDate(new Date())}`,
    `DTSTART:${toIcsDate(start)}`,
    `DTEND:${toIcsDate(end)}`,
    `SUMMARY:SkillSwap: ${booking.skill_name} with ${otherName}`,
    `DESCRIPTION:${booking.skill_name} tutoring session (${booking.duration}h) via SkillSwap.`,
    'END:VEVENT',
    'END:VCALENDAR'
  ]
  return lines.join('\r\n')
}

export function downloadBookingIcs(booking) {
  const ics = buildBookingIcs(booking)
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `skillswap-booking-${booking.booking_id}.ics`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
