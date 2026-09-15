The next page should be the Dashboard. Since it's the first page users see after logging in, it should provide a clear overview of the association using the /api/dashboard endpoint.

Dashboard Layout
┌──────────────────────────────────────────────────────────────┐
│ Sidebar │ Top Navbar                                        │
│         ├────────────────────────────────────────────────────┤
│         │ Welcome, Mohamed 👋          Search   Profile      │
│         ├────────────────────────────────────────────────────┤
│         │ Statistics Cards                                  │
│         ├────────────────────────────────────────────────────┤
│         │ Subscription Chart    │ Project Status Chart       │
│         ├────────────────────────────────────────────────────┤
│         │ Recent Projects       │ Quick Actions              │
└──────────────────────────────────────────────────────────────┘
Sidebar
Dashboard

Members
Subscriptions
Projects
Documents
Reports
Activity Logs

Profile
Logout
Top Navbar

Left:

Dashboard

Right:

User avatar
User name
Logout button
Statistics Cards

The backend already returns everything needed.

Card 1
👥 Members

120
Card 2
✅ Verified Subscriptions

80
Card 3
⏳ Pending

10
Card 4
❌ Expired

20
Card 5
💰 Revenue

24,000 MAD
Card 6
📁 Projects

12
Charts
Subscription Status

Doughnut chart

Verified
Pending
Expired
Project Status

Bar chart

Planned
Active
Completed
Cancelled
Recent Projects

A table using the projects endpoint.

Project	Status	Budget	Manager
Community Cleanup	Active	5000 MAD	Admin
Quick Actions

Large action buttons:

+ Add Member

+ New Subscription

+ Create Project

+ Upload Document
Mobile Layout

Cards become a vertical list:

Members

Verified

Pending

Expired

Revenue

Projects

Charts stack below them.

Data Flow

On page load:

GET /api/dashboard

Fill the statistics and charts.

For the "Recent Projects" section:

GET /api/projects

Display the latest 5 projects.