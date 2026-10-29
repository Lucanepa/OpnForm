/**
 * KSCW fork: tell the API which language the respondent of a multilingual form reads, so
 * validation and "check in Wiedisync" messages come back in it (FormTranslations.php).
 * Only public form requests (`/forms/...`), only once a form offers more than English.
 */
export function addKscwFormLocale(request, opts) {
  if (typeof request !== 'string' || !/^\/?forms\//.test(request)) return
  const locale = useState('kscw-form-locale').value
  if (locale) opts.headers = { ...opts.headers, 'x-form-locale': locale }
}
