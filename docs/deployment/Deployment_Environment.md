# IPCA Courseware Deployment Environment

This document describes the live system environment used by IPCA Courseware.

---

# Application Stack

PHP Version

8.2.x

Server Interface

apache2handler

Database

MySQL 8.0.x

Deployment Platform

DigitalOcean App Platform

Source Control

GitHub

---

# Storage

DigitalOcean Spaces

Primary bucket:

ipca-media

Structure:

ipca-media/
  ks_images/
    private/
    instrument/
    commercial/

  ks_videos/
    private/
    instrument/
    commercial/

  progress_tests_v2/
    {test_id}/
      intro.mp3
      question audio
      answers/

Spaces CDN used for public delivery.

---

# Email Infrastructure

Provider

Postmark

Sender domain

ipca.aero

Primary sender

info@ipca.aero

Email usage

• remediation notifications  
• instructor intervention notices  
• summary revision alerts  
• future operational communication  

Delivery history stored in:

training_progression_emails

---

# Development Workflow

Local Development

Dreamweaver editor

Workflow

Edit code  
Commit to GitHub  
DigitalOcean deployment  
Test on live environment

Database changes performed via:

TablePlus

Terminal usage intentionally minimized.

---

# Environment Variables

Database

CW_DB_HOST  
CW_DB_PORT  
CW_DB_NAME  
CW_DB_USER  
CW_DB_PASS  

OpenAI

CW_OPENAI_API_KEY  
CW_OPENAI_MODEL  

Spaces

CW_SPACES_KEY  
CW_SPACES_SECRET  
CW_SPACES_BUCKET  
CW_SPACES_ENDPOINT  
CW_SPACES_REGION  
CW_SPACES_CDN_BASE  

CDN

CW_CDN_BASE

Email

POSTMARK_SERVER_TOKEN (future if direct API used)

---

# Security

Sessions stored in database table:

php_sessions

Session handler implemented in:

src/session_db.php

Cookies configured as:

Secure  
HTTPOnly  
SameSite=Lax

---

# Backup Strategy

Recommended:

Daily database backup  
Weekly Spaces media snapshot  
GitHub repository version history  

Future enhancement:

automated deployment health monitoring