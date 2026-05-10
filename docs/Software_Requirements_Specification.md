# Software Requirements Specification

> Converted from `Software Requirements Specification.pdf` to Markdown.


<!-- Page 1 -->

Software Requirements Specification (SRS)

Driving License Digital Management System (DLMS)

1. Introduction

### 1.1 Purpose

تهدف هذه الوثيقة إلى تقديم توصيف رسمي ودقيق لمتطلبات نظام إدارة رخص القيادة الرقمية، من خالل توثيق المتطلبات
الوظيفية وغير الوظيفية بطريقة منظمة وواضحة.

تُستخدم هذه الوثيقة كمرجع أساسي لـ:

- فريق التحليل والتصميم
- فريق التطوير
-
فريق االختبار
- المشرف األكاديمي
وذلك لضمان فهم موحد لمتطلبات النظام.

### 1.2 Scope

يهدف النظام إلى رقمنة وإدارة جميع عمليات رخص القيادة ضمن جهة حكومية (مديرية النقل)، ويشمل:

يشمل النظام:

-
تطبيق موبايل للمواطنين
- لوحة تحكم للموظفين واإلدارة
-
نظام إدارة الطلبات والرخص
-
نظام اختبارات متسلسل
- نظام مواعيد
-
)نظام دفع إلكتروني (محاكاة
-
نظام مرفقات
-
Chatbot
مساعد

<!-- Page 2 -->

ال يشمل:

- التكامل الفعلي مع بوابات دفع محلية
-
)التكامل مع قواعد بيانات حكومية خارجية (يتم محاكاته

### 1.3 Definitions, Acronyms, and Abbreviations

الوصف المصطلح
SRS
Software Requirements Specification
DLMS
Driving License Management System
OTP
One-Time Password
RBAC
Role-Based Access Control
License رخصة قيادة
Test
)اختبار (نظر / نظري / عملي

### 1.4 References

- IEEE 830 / IEEE 29148
- Software Engineering Course Material
- UML Standards
- Draw.io

### 1.5 Document Overview

-
الفصل1
: مقدمة
-
الفصل2
: وصف عام للنظام
-
الفصل3
: System Features

-
الفصل4
: Interfaces

-
الفصل5
: Non-Functional Requirements

-
الفصل6
: Other Requirements


<!-- Page 3 -->

-
الفصل7
: Appendices

2. Overall Description

### 2.1 Product Perspective

النظام يتكون من:

-
Mobile App
المواطن
-
Admin Dashboard الموظفين
-
Backend API

-
Database

Architecture:

Client–Server Architecture

### 2.2 Product Functions

-
إدارة طلبات الرخص
- إدارة االختبارات
- إدارة المواعيد
-
إدارة الدفع
- إدارة المرفقات
-
إدارة الرخص
- إدارة المستخدمين
-
Chatbot
مساعد


<!-- Page 4 -->

### 2.3 User Classes

المستخدم
الوصف
Citizenمستخدم عادي يقدم طلبات
Employeeموظف معالجة
Adminمدير النظام

### 2.4 Operating Environment

- Mobile: Android / iOS
- Web Dashboard
- Server + Database
- SMS Gateway (OTP)

### 2.5 Constraints

- اعتماد OTP

- دعم اللغة العربية
-
Workflow
حكومي صارم
- محاكاة الدفع اإللكتروني

### 2.6 Assumptions

-
توفر اإلنترنت
- صحة بيانات المستخدم
- التزام المستخدم بالقوانين


<!-- Page 5 -->

3. System Features

SF-1 User Authentication

Description

إدارة تسجيل الدخول والتوثيق.

Functional Requirements

- FR-01 Sign Up
- FR-02 Login
- FR-03 OTP Verification
- FR-04 Reset Password

SF-2 License Application Management

Description

تقديم طلب رخصة.

Functional Requirements

- FR-05 Create Application
- FR-06 Select License Type
- FR-07 Validate Eligibility
- FR-08 Upload Documents

SF-3 Workflow Engine

Description

إدارة مراحل الطلب.

Functional Requirements

- FR-09 Define Workflow
- FR-10 Move Between Stages
- FR-11 Enforce Business Rules

<!-- Page 6 -->

SF-4 Test Management

Description

إدارة االختبارات.

Functional Requirements

- FR-12 Schedule Vision Test
- FR-13 Schedule Theory Test
- FR-14 Schedule Practical Test
- FR-15 Record Result
- FR-16 Retake Test

SF-5 Appointment Management

Description

إدارة المواعيد.

Functional Requirements

- FR-17 Book Appointment
- FR-18 Cancel Appointment
- FR-19 Reschedule

SF-6 Payment Management

Description

الدفع اإللكتروني.

Functional Requirements

- FR-20 Pay Fees
- FR-21 Validate Payment
- FR-22 Link Payment to Application


<!-- Page 7 -->

SF-7 License Issuance

Description

إصدار الرخصة.

Functional Requirements

- FR-23 Approve License
- FR-24 Generate License
- FR-25 Print / Export License

SF-8 Document Management

Description

إدارة المرفقات.

Functional Requirements

- FR-26 Upload Documents
- FR-27 Validate Documents
- FR-28 Store Documents

SF-9 RBAC

Functional Requirements

- FR-29 Manage Roles
- FR-30 Assign Permissions

SF-10 Chatbot

Functional Requirements

- FR-31 Answer Questions
- FR-32 Guide User


<!-- Page 8 -->

4. External Interface Requirements

### 4.1 User Interfaces

Mobile App:

-
تسجيل
-
تقديم طلب
- رفع ملفات
- تتبع الحالة
Dashboard:

- إدارة الطلبات
- إدارة االختبارات
- التقارير

### 4.2 Software Interfaces

- REST API
- Database (MySQL/PostgreSQL)
- SMS Gateway
- Payment Gateway (Test Mode)

### 4.3 Communication Interfaces

- HTTPS
- JSON APIs

5. Non-Functional Requirements

### 5.1 Performance

-
Response < 2s

-
دعم مستخدمين متزامنين


<!-- Page 9 -->

### 5.2 Security

-
OTP

-
HTTPS

-
RBAC

-
عدم تخزين بيانات الدفع

### 5.3 Reliability

-
عدم فقدان البيانات
- التعامل مع األعطال

### 5.4 Usability

- واجهة بسيطة
- دعم عربي كامل

### 5.5 Scalability

-
إضافة خدمات مستقبلية

### 5.6 Maintainability

-
Modular Design

### 5.7 Availability

-
99% uptime

6. Other Requirements

### 6.1 Data Integrity

- عدم تكرار الطلبات
-
الحفاظ على العالقات

### 6.2 Logging

-
تسجيل كل العمليات

### 6.3 Backup

-
نسخ احتياطي يومي


<!-- Page 10 -->

### 6.4 Localization

- دعم عربي

7. Appendices

Appendix A: Business Rules

-
ال يمكن التقدم الختبار دون النجاح بالسابق
- ال يمكن إصدار رخصة بدون دفع
- ال يمكن وجود طلبين نشطين لنفس المستخدم

Appendix B: UML Diagrams

- Use Case Diagram
- Sequence Diagram
- Activity Diagram
- Class Diagram
- Communication Diagram
- State Diagram
