# MamaKind UX Audit Report

**Site:** https://mamakindapp.com  
**Date:** 2026-06-08  
**Build:** v4.4.22 · b521173  
**Auditor:** Automated UX Audit  

---

## Summary

| Severity | Count |
|----------|-------|
| 🔴 Critical | 3 |
| 🟡 Medium | 6 |
| 🟢 Minor | 7 |
| **Total** | **16** |

The site is well-built overall — clean design, solid navigation, proper accessibility basics, and good responsive behavior. The **dominant issue** is broken Amazon product images (93% failure rate on the Products page) and incorrect Amazon affiliate links (wrong ASINs mapping to wrong products). These are critical because they directly impact trust and revenue.

---

## 🔴 Critical Issues

### 1. Massive Broken Product Images (71 of 76 on /products)
**Pages affected:** `/products`, `/breastfeeding-safe-products`, `/ttc-safe-products`, `/pregnancy-safe-products`, `/ingredients/*`, `/products/*` (detail pages), curated list pages  
**Description:** 93% of product images fail to load. All images are hotlinked from Amazon's CDN (`m.media-amazon.com`). Amazon frequently rotates image URLs, causing 404 errors. Only 5 of 76 product images currently load on the Products page.  
**Console errors (examples):**
- `https://m.media-amazon.com/images/I/61DVN+YWyOL._AC_SX522_.jpg` → 404
- `https://m.media-amazon.com/images/I/61S7BrCBjUL._AC_SX522_.jpg` → 404
- `https://m.media-amazon.com/images/I/71vN7c+LtUL._AC_SX522_.jpg` → 404
- `https://m.media-amazon.com/images/P/B000JVCBBG.01._SL500_.jpg` → 404
- And 67 more...

**Impact:** Users see broken image placeholders for nearly every product. This severely undermines trust for a site that relies on product recommendations.  
**Recommendation:** 
- Self-host product images or use a CDN proxy with fallback
- Implement a scheduled job to check image URLs and refresh them
- Add a fallback/placeholder image for broken loads via `onerror` handler

---

### 2. Wrong Amazon Affiliate Links (ASIN Mismatches)
**Page affected:** `/products`  
**Description:** Multiple different products link to the same Amazon ASIN, meaning users clicking "View on Amazon" are sent to the **wrong product page**. This is a revenue and trust issue.

**Duplicate ASINs found:**
| ASIN | Products Using It (All Different Products) |
|------|-------------------------------------------|
| `B08QX1YK6D` | Cetaphil Daily Hydrating Lotion SPF 15, Neutrogena Hydro Boost Day Gel-Cream, Neutrogena Hydro Boost Eye Gel-Cream, Neutrogena Hydro Boost Night Gel-Cream, Neutrogena Sheer Zinc SPF 50 Sunscreen |
| `B01H06YZ1G` | Fertility Friend BBT Thermometer, Pre-Seed Fertility Lubricant, Clearblue Advanced Ovulation Test, Easy@Home Ovulation Strips, First Response Pregnancy Test |
| `B01KIQH2VU` | Leachco Snoogle Pillow, PharMeDoc C-Shape Pillow, Queen Rose Wedge Pillow |
| `B0BNP3Y6CT` | Bamboobies Nursing Pillow, Boppy Pregnancy Pillow |
| `B00BLA3EPQ` | Lansinoh HPA Lanolin Nipple Cream, Mustela Stretch Marks Cream, The Honest Company Belly Balm |
| `B0843XGN3H` | CeraVe Eye Repair Cream, CeraVe Hydrating Facial Cleanser, CeraVe Hydrating Hyaluronic Acid Serum |
| `B07PP38QDY` | ATTITUDE Pregnancy Body Lotion, ATTITUDE Pregnancy Shampoo, The Inkey List Niacinamide Serum |
| `B09CFBW8NY` | Traditional Medicinals Mother's Milk Tea, Milkmakers Lactation Cookie Bites |
| `B0055OAUA6` | Gin Gins Ginger Candy, Three Lollies Preggie Pop Drops |
| `B005EF0I7Q` | Burt's Bees Mama Bee Belly Butter, Burt's Bees Mama Bee Body Oil |

**Impact:** Users clicking affiliate links are redirected to incorrect products. This causes confusion, lost sales, and potential Amazon Associates policy violations.  
**Recommendation:** Audit every product's Amazon ASIN and correct the links. Each product must have its own unique, correct ASIN.

---

### 3. Hub Page Images Returning 404s
**Pages affected:** `/breastfeeding-safe-products`, `/ttc-safe-products`  
**Description:** The stage hub pages load broken Amazon images, producing multiple console 404 errors. The `/pregnancy-safe-products` page is cleaner but still affected on product cards.  
**Impact:** Key landing pages for the three main user segments show broken images, hurting first impressions.

---

## 🟡 Medium Issues

### 4. Contact Page Has No Contact Form
**Page:** `/contact`  
**Description:** The contact page only shows an email link (`support@mamakindapp.com`) and a mailing address. There is no contact form, which is the expected UX pattern for most websites.  
**Impact:** Users expecting to fill out a form may bounce. Some users may not have an email client configured.  
**Recommendation:** Add a simple contact form (name, email, message) that sends to the support inbox. Keep the email link as an alternative.

---

### 5. Newsletter Email Signup — No Success State Visible
**Page:** `/` (homepage, bottom section)  
**Description:** The newsletter signup form validates empty/invalid email correctly (shows "Please enter a valid email address"). However, after valid submission, there's no clear visual confirmation state observable in the DOM. The experience after submitting a valid email should provide obvious positive feedback.  
**Recommendation:** Ensure a clear "Thank you! You're subscribed." message replaces or appears near the form after successful submission.

---

### 6. Preloaded Font Not Used
**Pages affected:** All pages (observed on 404 page, likely site-wide)  
**Console warning:** `The resource https://mamakindapp.com/_next/static/media/5fbf91cac4d9174b-s.p.0h.mz599u2a38.woff2 was preloaded using link preload but not used within a few seconds from the window's load event.`  
**Impact:** Unnecessary network request, minor performance hit. Browsers flag this as a warning.  
**Recommendation:** Either use the font or remove the preload hint from the `<head>`.

---

### 7. Products Filter — "Refine by Type" Has Too Many Options
**Page:** `/products`  
**Description:** The "Refine by type" filter section lists 30+ subcategories (Cream, Day Cream, Serum, Eye Cream, Night Cream, Stretch Mark Care, Nausea Relief, Pregnancy Pillow, etc.), many with only 1-2 items. This creates an overwhelming filter experience.  
**Impact:** Users face decision paralysis with too many low-count filter options.  
**Recommendation:** Consider grouping related types (e.g., "Creams & Moisturizers" instead of separate Cream, Day Cream, Night Cream) or hiding types with ≤2 items behind a "Show more" toggle.

---

### 8. Check a Product Page — No Loading Indicator Context
**Page:** `/check`  
**Description:** When a product is searched, results appear but there's no visible loading spinner or "Searching..." state during the lookup. For products that require AI-assisted analysis (non-catalog matches), this delay could be confusing.  
**Recommendation:** Add a loading skeleton or spinner between form submission and result display, especially for non-catalog lookups.

---

### 9. Build Version Exposed in Footer
**Pages affected:** All pages  
**Description:** The footer displays `Build v4.4.22 · b521173` (version number and git commit hash) on every page.  
**Impact:** Exposes internal build information to all users. While not a security vulnerability per se, it's unnecessary for end users and could aid targeted attacks if vulnerabilities are found in specific versions.  
**Recommendation:** Remove or hide build info from production footer, or restrict to admin/dev views only.

---

## 🟢 Minor Issues

### 10. Curated Lists — No "Back to Top" Button on Long Pages
**Pages affected:** `/products`, `/pregnancy-safe-products`, other long-scroll pages  
**Description:** Product listing and hub pages are very long (76+ products). There's no "back to top" button or sticky navigation to help users navigate.  
**Recommendation:** Add a floating "back to top" button that appears after scrolling past the first viewport.

---

### 11. Mobile Hamburger Menu — No Visual Close Indicator
**Page:** All pages at 375px width  
**Description:** The mobile navigation uses a hamburger (☰) icon. While it works correctly, the open/close state could be more obvious (e.g., animate to an X when open).  
**Recommendation:** Consider a hamburger-to-X animation for clearer state communication.

---

### 12. Homepage — Dense Content Structure
**Page:** `/`  
**Description:** The homepage is content-rich with many sections: Hero → How It Works → Curated Lists → Check a Product → Methodology → TTC → Browse by Category → Popular Guides → Quick Guides → Newsletter → About → Contact. While comprehensive, it may be overwhelming for first-time visitors.  
**Recommendation:** Consider a more focused above-the-fold experience with progressive disclosure. A sticky section nav could help users jump to relevant sections.

---

### 13. Consistent Card Design for Product Categories
**Page:** `/products`  
**Description:** Product categories use emoji prefixes (🤱, 💄, 🍎, 🛏️, 💊, 🌸) as section headers, which is informal. The visual hierarchy between categories could be stronger.  
**Recommendation:** Consider styled category headers with icons instead of emoji for a more polished look.

---

### 14. Footer "Cookie Preferences" Button — Duplicated
**Pages affected:** All pages  
**Description:** The "Cookie preferences" button appears twice in the footer — once in the "Legal" section and once in the bottom bar. This is slightly redundant.  
**Recommendation:** Keep only one instance, preferably in the bottom bar alongside Privacy/Terms links.

---

### 15. Product Detail Pages — No Breadcrumb Navigation
**Pages affected:** `/products/cerave-moisturizing-cream` and other product detail pages  
**Description:** Individual product pages lack breadcrumb navigation to help users understand where they are in the site hierarchy and navigate back to the products list or relevant category.  
**Recommendation:** Add breadcrumbs like: Home > Products > Cosmetics & Skincare > CeraVe Moisturizing Cream

---

### 16. Blog Posts — No Reading Progress Indicator
**Pages affected:** All blog posts (e.g., `/blog/best-prenatal-vitamins-canada`)  
**Description:** Blog posts show estimated reading time (e.g., "7 min read") but no reading progress bar, which is a common UX pattern for long-form content.  
**Recommendation:** Consider adding a subtle progress bar at the top of blog posts.

---

## ✅ What Works Well

- **Navigation:** Clean, consistent 6-item nav across all pages. Mobile hamburger works correctly. All nav links functional.
- **404 Page:** Properly branded with full navigation and footer. Clear "404 / This page could not be found" messaging.
- **Responsive Design:** Tested at 375px (mobile), 768px (tablet), and 1280px (desktop). Layout adapts well at all breakpoints with no layout breakage observed.
- **Accessibility Basics:**
  - ✅ Skip to main content link present
  - ✅ Proper heading hierarchy (H1→H2→H3→H4) on all pages tested
  - ✅ All images have alt text (0 missing on tested pages)
  - ✅ Form inputs have proper labels and placeholders
  - ✅ All external links use `rel="noopener"` (76/76 on Products page)
  - ✅ ARIA roles used appropriately (landmarks, regions, alerts)
- **Form Validation:** Check a Product form validates empty submissions with clear error message. Email signup validates invalid emails properly.
- **Check a Product Feature:** Works correctly — returns product match with safety rating, summary, and flagged ingredients.
- **Interactive Elements:** Search tips `<details>` expandable works. Filter checkboxes on Products page work. Cookie consent banner is functional.
- **Analytics Consent:** GDPR-style consent banner with Accept/Decline options. Links to privacy policy. Non-intrusive.
- **Content Quality:** Well-written, authoritative content with proper disclaimers, source citations, and "not medical advice" warnings throughout.
- **No JavaScript Errors:** Zero JS runtime errors across all pages. Only console errors are the Amazon image 404s.
- **Performance:** Pages load quickly. No noticeably slow elements. Next.js framework provides good performance baseline.
- **SEO:** Proper page titles, meta descriptions (inferred from Next.js structure), semantic HTML, and proper heading structure.

---

## Pages Audited

| Page | URL | Status | Console Errors |
|------|-----|--------|----------------|
| Homepage | `/` | ✅ Clean | None |
| Products | `/products` | ⚠️ 71 broken images | 71× Amazon image 404 |
| Curated Lists | `/curated-lists` | ✅ Clean | None |
| Check a Product | `/check` | ✅ Clean | None |
| Blog Index | `/blog` | ✅ Clean | None |
| Contact | `/contact` | ✅ Clean | None |
| Privacy Policy | `/privacy` | ✅ Clean | None |
| Terms of Service | `/terms` | ✅ Clean | None |
| Affiliate Disclosure | `/affiliate` | ✅ Clean | None |
| Pregnancy Hub | `/pregnancy-safe-products` | ✅ Clean | None |
| Breastfeeding Hub | `/breastfeeding-safe-products` | ⚠️ Broken images | Amazon image 404s |
| TTC Hub | `/ttc-safe-products` | ⚠️ Broken images | Amazon image 404s |
| Retinol Guide | `/ingredients/retinol-pregnancy` | ⚠️ Broken images | Amazon image 404s |
| Salicylic Acid Guide | `/ingredients/salicylic-acid-pregnancy` | ⚠️ Broken images | Amazon image 404s |
| Azelaic Acid Guide | `/ingredients/azelaic-acid-pregnancy` | ⚠️ Broken images | Amazon image 404s |
| Blog: Best Prenatals | `/blog/best-prenatal-vitamins-canada` | ✅ Clean | None |
| Blog: TTC Skincare | `/blog/ttc-skincare-routine-canada` | ✅ Clean | None |
| Blog: Pregnancy Skincare | `/blog/pregnancy-safe-skincare-canada` | ✅ Clean | None |
| Blog: Gentle Iron | `/blog/gentle-iron-pregnancy` | ✅ Clean | None |
| Blog: Morning Sickness | `/blog/morning-sickness-safe-remedies` | ✅ Clean | None |
| Curated: AM Routine | `/curated-lists/pregnancy-am-skincare-routine` | ✅ Clean | None |
| Product Detail | `/products/cerave-moisturizing-cream` | ⚠️ Broken images | Amazon image 404s |
| 404 Page | `/this-page-does-not-exist` | ✅ Proper 404 | Font preload warning |

---

## Responsive Testing Summary

| Breakpoint | Width | Result |
|------------|-------|--------|
| Mobile | 375px | ✅ Good — hamburger menu, single column, readable text |
| Tablet | 768px | ✅ Good — nav wraps nicely, content uses available space |
| Desktop | 1280px | ✅ Good — full nav bar, multi-column layouts, sidebar filters |

---

## Priority Recommendations

1. **🔴 URGENT:** Fix Amazon product image URLs or self-host images with a fallback system
2. **🔴 URGENT:** Audit and correct all Amazon affiliate ASINs — many products link to wrong items
3. **🟡 IMPORTANT:** Add a contact form to the Contact page
4. **🟡 IMPORTANT:** Remove build version from public footer
5. **🟢 NICE-TO-HAVE:** Add breadcrumbs, back-to-top button, and loading states for polish
