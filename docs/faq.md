---
title: "FAQ"
nav_order: 14
description: "Short answers about darvis/laravel-mfa: adding two factor authentication to Laravel, magic links, what happens when email is down, the firewall, and what it works with."
faq: true
---

# Frequently asked questions

{% for item in site.data.faq %}
## {{ item.q }}

{{ item.a | markdownify }}
{% endfor %}
