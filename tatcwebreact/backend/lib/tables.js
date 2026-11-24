export const TABLES = {
  participants: 'participants', instructors: 'instructors', rooms: 'rooms', programs: 'programs', modules: 'modules',
  batches: 'batches', sessions: 'sessions', exams: 'exams', exam_results: 'exam_results', certificates: 'certificates',
  notifications: 'notifications', users: 'users',
  'cms/banners': 'cms_banners', 'cms/partners': 'cms_partners', 'cms/news': 'cms_news',
  'module_topics': 'module_topics',
};

export const ADMIN_ROLES = ['SUPERADMIN', 'ADMIN', 'QA', 'MANAGEMENT'];
export const READ_ONLY_ADMIN_ROLES = ['QA', 'R06_QA', 'MANAGEMENT', 'MANAJEMEN', 'R08_MANAGEMENT'];
export const READ_ONLY_ADMIN_EMAILS = [
  'qa@tatc.id',
  'umismd@gmail.com',
  'asfriyantot@gmail.com',
  'indset69@gmail.com',
  'anggarusdinar@telkomuniversity.ac.id',
  'manajemen@tatc.id',
  'management@tatc.id',
];
export const ROLE_ORDER = ['SUPERADMIN', 'ADMIN', 'QA', 'MANAGEMENT', 'INSTRUKTUR', 'PESERTA'];

