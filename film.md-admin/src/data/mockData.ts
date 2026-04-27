export const mockStats = {
  totalFilms: 1248,
  totalSeries: 86,
  activeUsers: 45200,
  revenue: 125400, // MDL
  pendingReviews: 12
};

export const mockFilms = [
{
  id: 'f1',
  title: 'Carbon',
  originalTitle: 'Carbon',
  year: 2022,
  country: 'Moldova',
  duration: 103,
  ageRating: '16+',
  status: 'published',
  type: 'film',
  views: 45000
},
{
  id: 'f2',
  title: 'Teambuilding',
  originalTitle: 'Teambuilding',
  year: 2022,
  country: 'Romania',
  duration: 91,
  ageRating: '16+',
  status: 'published',
  type: 'film',
  views: 120000
},
{
  id: 'f3',
  title: 'Mirciulică',
  originalTitle: 'Mirciulică',
  year: 2022,
  country: 'Romania',
  duration: 90,
  ageRating: '16+',
  status: 'published',
  type: 'film',
  views: 85000
},
{
  id: 'f4',
  title: 'Nunta Mută',
  originalTitle: 'Nunta Mută',
  year: 2008,
  country: 'Romania',
  duration: 87,
  ageRating: '12+',
  status: 'published',
  type: 'film',
  views: 32000
},
{
  id: 'f5',
  title: 'Filantropica',
  originalTitle: 'Filantropica',
  year: 2002,
  country: 'Romania',
  duration: 110,
  ageRating: '12+',
  status: 'published',
  type: 'film',
  views: 54000
},
{
  id: 'f6',
  title: 'Afacerea Est',
  originalTitle: 'Afacerea Est',
  year: 2016,
  country: 'Moldova',
  duration: 87,
  ageRating: '16+',
  status: 'ready',
  type: 'film',
  views: 0
},
{
  id: 'f7',
  title: 'Milika',
  originalTitle: 'Milika',
  year: 2023,
  country: 'Moldova',
  duration: 85,
  ageRating: '12+',
  status: 'draft',
  type: 'film',
  views: 0
},
{
  id: 'f8',
  title: 'Moromeții 2',
  originalTitle: 'Moromeții 2',
  year: 2018,
  country: 'Romania',
  duration: 109,
  ageRating: '12+',
  status: 'archived',
  type: 'film',
  views: 21000
}];


export const mockSeries = [
{
  id: 's1',
  title: 'Las Fierbinți',
  originalTitle: 'Las Fierbinți',
  year: 2012,
  country: 'Romania',
  seasons: 22,
  ageRating: '12+',
  status: 'published',
  type: 'series',
  views: 500000
},
{
  id: 's2',
  title: 'Umbre',
  originalTitle: 'Umbre',
  year: 2014,
  country: 'Romania',
  seasons: 3,
  ageRating: '18+',
  status: 'published',
  type: 'series',
  views: 300000
},
{
  id: 's3',
  title: 'Hackerville',
  originalTitle: 'Hackerville',
  year: 2018,
  country: 'Romania',
  seasons: 1,
  ageRating: '16+',
  status: 'published',
  type: 'series',
  views: 80000
},
{
  id: 's4',
  title: 'Clanul',
  originalTitle: 'Clanul',
  year: 2022,
  country: 'Romania',
  seasons: 2,
  ageRating: '16+',
  status: 'ready',
  type: 'series',
  views: 0
},
{
  id: 's5',
  title: 'Bani Negri',
  originalTitle: 'Bani Negri',
  year: 2020,
  country: 'Romania',
  seasons: 1,
  ageRating: '16+',
  status: 'draft',
  type: 'series',
  views: 0
}];


export const mockUsers = [
{
  id: 'u1',
  name: 'Ion Popescu',
  email: 'ion.popescu@example.md',
  role: 'user',
  status: 'active',
  registered: '2023-01-15',
  lastActive: '2023-10-25'
},
{
  id: 'u2',
  name: 'Maria Ionescu',
  email: 'maria.i@example.md',
  role: 'user',
  status: 'active',
  registered: '2023-02-20',
  lastActive: '2023-10-26'
},
{
  id: 'u3',
  name: 'Andrei Ciobanu',
  email: 'andrei.c@admin.filmoteca.md',
  role: 'super-admin',
  status: 'active',
  registered: '2022-11-01',
  lastActive: '2023-10-27'
},
{
  id: 'u4',
  name: 'Elena Rusu',
  email: 'elena.r@example.md',
  role: 'user',
  status: 'suspended',
  registered: '2023-05-10',
  lastActive: '2023-09-15'
},
{
  id: 'u5',
  name: 'Vasile Munteanu',
  email: 'vasile.m@moderator.filmoteca.md',
  role: 'moderator',
  status: 'active',
  registered: '2022-12-05',
  lastActive: '2023-10-27'
},
{
  id: 'u6',
  name: 'Ana Ceban',
  email: 'ana.ceban@example.md',
  role: 'user',
  status: 'active',
  registered: '2023-08-12',
  lastActive: '2023-10-24'
},
{
  id: 'u7',
  name: 'Radu Sirbu',
  email: 'radu.s@example.md',
  role: 'user',
  status: 'active',
  registered: '2023-09-01',
  lastActive: '2023-10-26'
},
{
  id: 'u8',
  name: 'Diana Rotaru',
  email: 'diana.r@example.md',
  role: 'user',
  status: 'active',
  registered: '2023-07-22',
  lastActive: '2023-10-20'
}];


export const mockAuditLog = [
{
  id: 'al1',
  timestamp: '2023-10-27 10:45',
  user: 'Andrei Ciobanu',
  action: 'Published',
  target: 'Film: Carbon',
  details: 'Changed status from Ready to Published'
},
{
  id: 'al2',
  timestamp: '2023-10-27 09:30',
  user: 'Vasile Munteanu',
  action: 'Updated',
  target: 'Series: Umbre',
  details: 'Updated SEO metadata for RO locale'
},
{
  id: 'al3',
  timestamp: '2023-10-26 16:20',
  user: 'Andrei Ciobanu',
  action: 'Created',
  target: 'Offer: Weekend Promo',
  details: 'Created new rental offer for 50 MDL'
},
{
  id: 'al4',
  timestamp: '2023-10-26 14:15',
  user: 'System',
  action: 'Processed',
  target: 'Media: carbon_1080p.mp4',
  details: 'HLS encoding completed successfully'
},
{
  id: 'al5',
  timestamp: '2023-10-26 11:05',
  user: 'Vasile Munteanu',
  action: 'Archived',
  target: 'Film: Moromeții 2',
  details: 'Moved to archive due to licensing expiration'
},
{
  id: 'al6',
  timestamp: '2023-10-25 16:30',
  user: 'Andrei Ciobanu',
  action: 'Created',
  target: 'Film: Milika',
  details: 'New draft content created'
},
{
  id: 'al7',
  timestamp: '2023-10-25 14:00',
  user: 'System',
  action: 'Processed',
  target: 'Media: teambuilding_4k.mp4',
  details: '4K encoding completed'
},
{
  id: 'al8',
  timestamp: '2023-10-25 10:20',
  user: 'Vasile Munteanu',
  action: 'Updated',
  target: 'Genre: Thriller',
  details: 'Deactivated genre from public filters'
},
{
  id: 'al9',
  timestamp: '2023-10-24 18:45',
  user: 'Andrei Ciobanu',
  action: 'Deleted',
  target: 'Offer: Old Promo',
  details: 'Removed expired promotional offer'
},
{
  id: 'al10',
  timestamp: '2023-10-24 15:30',
  user: 'System',
  action: 'Published',
  target: 'CMS: Privacy Policy',
  details: 'Auto-published scheduled page update'
},
{
  id: 'al11',
  timestamp: '2023-10-24 11:00',
  user: 'Vasile Munteanu',
  action: 'Created',
  target: 'User: Diana Rotaru',
  details: 'New user registered via admin invite'
},
{
  id: 'al12',
  timestamp: '2023-10-23 09:15',
  user: 'Andrei Ciobanu',
  action: 'Updated',
  target: 'Home: Hero Banner',
  details: 'Changed featured content to Carbon'
},
{
  id: 'al13',
  timestamp: '2023-10-23 08:00',
  user: 'System',
  action: 'Processed',
  target: 'Media: umbre_s3_poster.jpg',
  details: 'Image optimization completed'
}];


export const mockOffers = [
{
  id: 'o1',
  contentTitle: 'Carbon',
  type: 'rental',
  price: 49,
  currency: 'MDL',
  rentalDays: 2,
  quality: '4K',
  status: 'active',
  dates: '2023-01-01 - 2024-12-31'
},
{
  id: 'o2',
  contentTitle: 'Teambuilding',
  type: 'lifetime',
  price: 199,
  currency: 'MDL',
  rentalDays: null,
  quality: '1080p',
  status: 'active',
  dates: '2023-05-01 - 2025-12-31'
},
{
  id: 'o3',
  contentTitle: 'Las Fierbinți',
  type: 'free',
  price: 0,
  currency: 'MDL',
  rentalDays: null,
  quality: '720p',
  status: 'active',
  dates: 'Always'
},
{
  id: 'o4',
  contentTitle: 'Mirciulică',
  type: 'rental',
  price: 39,
  currency: 'MDL',
  rentalDays: 3,
  quality: '1080p',
  status: 'expired',
  dates: '2022-10-01 - 2023-01-01'
}];


export const mockPurchases = [
{
  id: 'p1',
  user: 'Ion Popescu',
  content: 'Carbon',
  type: 'Rental',
  amount: 49,
  currency: 'MDL',
  date: '2023-10-27 14:30',
  status: 'completed'
},
{
  id: 'p2',
  user: 'Maria Ionescu',
  content: 'Teambuilding',
  type: 'Lifetime',
  amount: 199,
  currency: 'MDL',
  date: '2023-10-26 09:15',
  status: 'completed'
},
{
  id: 'p3',
  user: 'Ana Ceban',
  content: 'Mirciulică',
  type: 'Rental',
  amount: 39,
  currency: 'MDL',
  date: '2023-10-25 20:45',
  status: 'refunded'
},
{
  id: 'p4',
  user: 'Radu Sirbu',
  content: 'Carbon',
  type: 'Rental',
  amount: 49,
  currency: 'MDL',
  date: '2023-10-25 18:20',
  status: 'completed'
},
{
  id: 'p5',
  user: 'Diana Rotaru',
  content: 'Umbre',
  type: 'Lifetime',
  amount: 249,
  currency: 'MDL',
  date: '2023-10-24 12:00',
  status: 'completed'
},
{
  id: 'p6',
  user: 'Ion Popescu',
  content: 'Las Fierbinți',
  type: 'Free',
  amount: 0,
  currency: 'MDL',
  date: '2023-10-23 16:30',
  status: 'completed'
},
{
  id: 'p7',
  user: 'Maria Ionescu',
  content: 'Carbon',
  type: 'Rental',
  amount: 49,
  currency: 'MDL',
  date: '2023-10-22 20:15',
  status: 'pending'
},
{
  id: 'p8',
  user: 'Radu Sirbu',
  content: 'Hackerville',
  type: 'Rental',
  amount: 39,
  currency: 'MDL',
  date: '2023-10-21 14:45',
  status: 'completed'
}];


export const mockTaxonomies = {
  genres: [
  { id: 'g1', name: 'Comedie', slug: 'comedie', count: 342, active: true },
  { id: 'g2', name: 'Dramă', slug: 'drama', count: 512, active: true },
  { id: 'g3', name: 'Acțiune', slug: 'actiune', count: 289, active: true },
  {
    id: 'g4',
    name: 'Documentar',
    slug: 'documentar',
    count: 124,
    active: true
  },
  { id: 'g5', name: 'Thriller', slug: 'thriller', count: 198, active: false }],

  categories: [
  {
    id: 'c1',
    name: 'Filme Românești',
    slug: 'filme-romanesti',
    count: 450,
    active: true
  },
  {
    id: 'c2',
    name: 'Filme Moldovenești',
    slug: 'filme-moldovenesti',
    count: 85,
    active: true
  },
  {
    id: 'c3',
    name: 'Blockbustere',
    slug: 'blockbustere',
    count: 120,
    active: true
  }]

};
