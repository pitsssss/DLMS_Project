# Activity Diagrams Report

> Converted from `Activity Diagrams Report.pdf` to Markdown.


<!-- Page 1 -->

Activity Diagrams Report
Digital License Management System - DLMS
نظام إدارة رخص القيادة الرقمية

1. Purpose of This Document
تهدف هذه الوثيقة إلى تحديد مخططات النشاط المطلوبة لنظام إدارة رخص القيادة الرقمية، وشرح طريقة بنائها ورسمها
بشكل دقيق ومنظم.

تعتمد هذه الوثيقة على:

- وثيقة SRS
الخاصة بالنظام.

-
Business Rules المعتمدة.

-
Use Case Descriptions النهائية.

-
Use Case Diagram النهائي.

الغرض من هذه الوثيقة هو تمكين فريق الرسم والتحليل من إنتاج Activity Diagrams
احترافية، واضحة، وغير متناقضة
مع باقي وثائق المشروع.

2. What Is an Activity Diagram?
مخطط النشاط هو أحد مخططات UML، ويُستخدم لتمثيل سير العمل داخل النظام خطوة بخطوة.

يوضح المخطط:

- بداية العملية.

- األنشطة التي تحدث.

- القرارات والشروط.

-
المسارات البديلة.

- المسؤول عن كل خطوة.

- نهاية العملية.


<!-- Page 2 -->

في مشروع DLMS
، مخططات النشاط مهمة جداً ألن النظام يعتمد على إجراءات متسلسلة مثل:

تقديم طلب

رفع مرفقات

مراجعة

دفع

اختبارات

إصدار رخصة

3. Why Activity Diagrams Are Needed in DLMS
نحتاج Activity Diagrams لألسباب التالية:

1
.
توضيح سير العمل الحقيقي لكل خدمة.

2
.
تحويل Use Cases إلى خطوات عملية قابلة للفهم.

3
.
التأكد من تطبيق Business Rules داخل السيناريوهات.

4
.
توضيح انتقال الطلب بين الحاالت المختلفة.

5
.
تحديد مسؤولية كل جهة: المواطن، النظام، الموظف، بوابة الدفع، اإلشعارات.

6
.
استخدامها الحقاً كأساس لرسم Sequence Diagrams.

7
.
مساعدة فريق البرمجة على فهم منطق النظام قبل كتابة الكود.

8
.
تقليل األخطاء في تصميم قاعدة البيانات والـ APIs.

4. General Drawing Rules
يجب على الفريق االلتزام بالقواعد التالية أثناء رسم المخططات:

1
.
ال يتم رسم تفاصيل برمجية مثل Controller أو API أو Database Query.

2
.
ال يتم رسم جداول قاعدة البيانات داخل Activity Diagram.

3
.
كل خطوة يجب أن تكون بصيغة فعل واضحة.

4
.
كل Decision
ًيجب أن يكون سؤاالً واضحا.

5
.
كل فرع خارج من Decision يجب أن يكون مسمى:

-
Yes / No

-
Valid / Invalid

-
Success / Failed

-
Accepted / Rejected

6
.
يجب استخدام Swimlanes عند وجود أكثر من Actor
مشارك.


<!-- Page 3 -->

7
.
يجب أن تكون أسماء األنشطة متوافقة مع أسماء Use Cases.

8
.
يجب أن تكون الحاالت متوافقة مع Business Rules.

9
.
يجب أال يكون المخطط مزدحماً أكثر من الالزم.

10
. إذا أصبح المخطط كبيراً، يتم تقسيمه إلى مخطط رئيسي ومخططات فرعية.

5. UML Elements Used

### 5.1 Initial Node
يمثل بداية العملية.

الشكل: دائرة سوداء صغيرة.

مثال:

Start

### 5.2 Action / Activity
يمثل خطوة يتم تنفيذها.

الشكل: مستطيل بحواف دائرية.

أمثلة:

-
Login

-
Upload Required Documents

-
Review Documents

-
Pay Fees

-
Record Test Result

### 5.3 Decision Node
ًيمثل شرطاً أو قرارا.

الشكل: معين Diamond.

أمثلة:


<!-- Page 4 -->

-
Is profile complete?

-
Are documents valid?

-
Is payment successful?

-
Did citizen pass the test?

### 5.4 Merge Node
يستخدم لجمع مسارات مختلفة في مسار واحد.

مثال:

بعد رفض المرفقات وإعادة رفعها، يعود المسار إلى مرحلة المراجعة.

### 5.5 Final Node
يمثل نهاية العملية.

الشكل: دائرة نهائية.

### 5.6 Swimlanes
تقسم المخطط حسب المسؤول عن كل نشاط.

الـ Swimlanes األساسية في النظام:

Swimlane
Description
Citizen
المواطن الذي يستخدم تطبيق الموبايل
System النظام الذي يتحقق ويغير الحاالت
Employee الموظف الذي يستخدم لوحة التحكم
Admin مدير النظام
Payment Gateway
بوابة الدفع
Notification Service خدمة اإلشعارات


<!-- Page 5 -->

6. Recommended Tools
Primary Tool
draw.io / diagrams.net
الرابط:

https://app.diagrams.net

سبب االعتماد:

- مجاني.

- سهل االستخدام.

- مناسب للمشاريع الجامعية.

- يدعم UML.

- يدعم Swimlanes.

- يمكن تصدير المخططات إلى PNG / SVG / PDF.

- يمكن حفظ الملفات بصيغة
.drawio.

How to Set Up draw.io
1
.
افتح الموقع: diagrams.net.

2
.
اختر: Device.

3
.

من القائمة اليسرى اختر: More Shapes.

4
.
فعّل:

-
UML

-
UML 2.5

-
Flowchart

5
.
استخدم:

-
Initial State

-
Activity

-
Decision


<!-- Page 6 -->

-
Final State

-
Swimlane / Container

7. Required Activity Diagrams
ال نحتاج إلى Activity Diagram لكل Use Case
ً، ألن ذلك سيجعل المشروع كبيراً ومكررا.

المطلوب رسم

### 6 مخططات رئيسية:

ID
Diagram Name
Priority
Purpose

## AD-01 New License Application Flow Very High المسار الكامل إلصدار رخصة جديدة

## AD-02 Document Review Flow
High
دورة رفع ومراجعة المرفقات

## AD-03 Payment Flow
High دورة الدفع والتحقق

## AD-04 Testing Flow
Very High تسلسل االختبارات وإعادة االختبار

## AD-05 License Issuance Flow
High إصدار الرخصة بعد اكتمال الشروط

## AD-06 Other License Services Flow
Medium التجديد، بدل فاقد، بدل تالف، فك الحجز

8. Naming Convention
يجب تسمية ملفات draw.io بالشكل التالي:

AD-01_New_License_Application_Flow.drawio
AD-02_Document_Review_Flow.drawio
AD-03_Payment_Flow.drawio
AD-04_Testing_Flow.drawio
AD-05_License_Issuance_Flow.drawio
AD-06_Other_License_Services_Flow.drawio
وعند التصدير:

AD-01_New_License_Application_Flow.png
AD-02_Document_Review_Flow.png

<!-- Page 7 -->

AD-03_Payment_Flow.png
AD-04_Testing_Flow.png
AD-05_License_Issuance_Flow.png
AD-06_Other_License_Services_Flow.png

9. Application Statuses Used in Activity Diagrams
يجب أن يستخدم الفريق الحاالت التالية فقط عند رسم حاالت الطلب:

Status
Meaning
Draft الطلب قيد اإلنشاء
Documents Under Review المرفقات قيد المراجعة
Documents Rejected
تم رفض المرفقات
Payment Pending بانتظار الدفع
Payment Completed
تم الدفع
Appointment Pending
بانتظار حجز موعد
In Testing الطلب في مرحلة االختبارات
Waiting Retest بانتظار إعادة اختبار
Approved
الطلب مؤهل لإلصدار
License Issued
تم إصدار الرخصة
Rejected
تم رفض الطلب
Cancelled
تم إلغاء الطلب
ممنوع استخدام حاالت غير موجودة في هذه القائمة إال بعد موافقة الفريق التحليلي.


<!-- Page 8 -->

10. Diagram AD-01
New License Application Flow

### 10.1 Purpose
يوضح هذا المخطط المسار الكامل إلصدار رخصة قيادة جديدة، من لحظة دخول المواطن إلى التطبيق وحتى إصدار
الرخصة.

هذا هو المخطط الرئيسي للنظام.

### 10.2 Related Use Cases
- UC-02 Login
- UC-03 Complete Profile
- UC-04 Submit New License Application
- UC-05 Upload Required Documents
- UC-06 Review Documents
- UC-07 Pay Fees
- UC-08 Book Test Appointment
- UC-10 View Test Results
- UC-11 Retake Failed Test
- UC-12 Record Test Result
- UC-13 Issue License
- UC-14 Track Application Status


<!-- Page 9 -->

### 10.3 Related Business Rules
- BR-02 Complete Profile Required
- BR-03 Prevent Duplicate Applications
- BR-04 Age Eligibility
- BR-05 License Type Required
- BR-06 Workflow Depends on License Type
- BR-07 Required Documents
- BR-10 Payment Required
- BR-13 Test Sequence
- BR-14 Prevent Skipping Tests
- BR-15 Retake Same Failed Test
- BR-23 License Issuance Conditions
- BR-41 Application Statuses
- BR-42 Prevent Status Skipping

### 10.4 Swimlanes
Swimlane
Activities
Citizen
Login, select service, upload documents, pay fees, book tests
System
Validate profile, eligibility, duplicate application, update statuses
Employee
Review documents, record test results, issue license
Payment Gateway
Process payment
Notification Service Send notifications


<!-- Page 10 -->

### 10.5 Step-by-Step Flow
Start
The process starts when the citizen opens the mobile application.

Citizen
1. Open Mobile Application.
2. Login.
3. Select “New License Application”.
4. Select License Type.

System
5. Check Profile Completion.

Decision 1
Is Profile Complete?
- No:
1. Citizen completes profile.
2. System saves profile.
3. Return to service selection.
- Yes:
Continue.

System
6. Check Citizen Eligibility.
7. Check Duplicate Active Application.


<!-- Page 11 -->

Decision 2
Is Citizen Eligible?
- No:
1. System shows rejection reason.
2. End.
- Yes:
Continue.

Decision 3
Does Duplicate Active Application Exist?
- Yes:
1. System shows existing active application.
2. End.
- No:
Continue.

System
8. Display Required Documents.

Citizen
9. Upload Required Documents.

System
10. Validate document format and completeness.
11. Create application.
12. Set application status to Documents Under Review.


<!-- Page 12 -->

Notification Service
13. Notify Employee about new application.

Employee
14. Review Documents.

Decision 4
Are Documents Accepted?
- No:
1. Employee rejects documents with reason.
2. System sets status to Documents Rejected.
3. Notification Service notifies Citizen.
4. Citizen re-uploads documents.
5. Return to Review Documents.
- Yes:
1. Employee approves documents.
2. System sets status to Payment Pending.
3. Notification Service notifies Citizen.

Citizen
15. Pay Fees.

Payment Gateway
16. Process Payment.


<!-- Page 13 -->

Decision 5
Is Payment Successful?
- No:
1. System keeps status as Payment Pending.
2. System shows payment failure message.
3. Citizen retries payment.
- Yes:
1. System records payment.
2. System sets status to Payment Completed.
3. System sets next status to Appointment Pending.
4. Notification Service sends confirmation.

Citizen
17. Book Vision Test Appointment.

Employee
18. Record Vision Test Result.

Decision 6
Did Citizen Pass Vision Test?
- No:
1. System sets status to Waiting Retest.
2. Citizen books retake for Vision Test only.
3. Return to Record Vision Test Result.
- Yes:
Continue.

<!-- Page 14 -->

Citizen
19. Book Theory Test Appointment.

Employee
20. Record Theory Test Result.

Decision 7
Did Citizen Pass Theory Test?
- No:
1. System sets status to Waiting Retest.
2. Citizen books retake for Theory Test only.
3. Return to Record Theory Test Result.
- Yes:
Continue.

Citizen
21. Book Practical Test Appointment.

Employee
22. Record Practical Test Result.


<!-- Page 15 -->

Decision 8
Did Citizen Pass Practical Test?
- No:
1. System sets status to Waiting Retest.
2. Citizen books retake for Practical Test only.
3. Return to Record Practical Test Result.
- Yes:
Continue.

System
23. Set application status to Approved.
24. Verify application completion.

Employee
25. Issue License.

System
26. Generate unique license number.
27. Create license record.
28. Set application status to License Issued.

Notification Service
29. Notify Citizen that license is issued.

End
The process ends after the license is issued.

<!-- Page 16 -->

### 10.6 Notes for Drawing AD-01
- This diagram may be large, so use horizontal Swimlanes.
- Keep testing steps compact.
- Do not draw database operations.
- Document review, payment, testing, and issuance are detailed separately in AD-02
to AD-05.
- AD-01 should show the complete flow but not every minor validation detail.

11. Diagram AD-02
Document Review Flow

### 11.1 Purpose
يوضح هذا المخطط دورة رفع المرفقات، التحقق منها، مراجعتها من قبل الموظف، قبولها أو رفضها.

### 11.2 Related Use Cases
- UC-05 Upload Required Documents
- UC-06 Review Documents

### 11.3 Related Business Rules
- BR-07 Required Documents
- BR-08 Document Review Required
- BR-09 Rejected Documents Must Be Re-uploaded
- BR-43 Rejection Reason Required
- BR-49 Uploaded Documents Are Not Automatically Approved


<!-- Page 17 -->

### 11.4 Swimlanes
Swimlane
Activities
Citizen
Upload documents, re-upload rejected documents
System
Validate format, save document, update status
Employee
Review, approve, reject
Notification Service Notify citizen

### 11.5 Step-by-Step Flow
Start
The flow starts when the citizen opens the documents page for an active application.

Citizen
1. Open Application Documents Page.
2. Select Document Type.
3. Upload Document.

System
4. Validate file format.
5. Validate file size.
6. Save document.
7. Set document status to Pending Review.


<!-- Page 18 -->

Decision 1
Are All Required Documents Uploaded?
- No:
1. System asks Citizen to upload missing documents.
2. Return to Upload Document.
- Yes:
Continue.

System
8. Set application status to Documents Under Review.

Employee
9. Open Pending Document Reviews.
10. Select Application.
11. Review Uploaded Documents.

Decision 2
Are Documents Valid?
- No:
1. Employee rejects document.
2. Employee enters rejection reason.
3. System sets document status to Rejected.
4. System sets application status to Documents Rejected.
5. Notification Service notifies Citizen.
6. Citizen re-uploads corrected document.
7. Return to Review Uploaded Documents.

<!-- Page 19 -->

- Yes:
1. Employee approves documents.
2. System sets document status to Approved.
3. System sets application status to Payment Pending.
4. Notification Service notifies Citizen.

End
The flow ends when documents are approved and the application moves to payment.

### 11.6 Notes for Drawing AD-02
- Use a loop from rejection back to re-upload.
- Rejection must include reason.
- Do not treat uploaded documents as automatically approved.
- Use the same document statuses used in the SRS.

12. Diagram AD-03
Payment Flow

### 12.1 Purpose
يوضح هذا المخطط طريقة دفع الرسوم والتحقق من نجاح العملية وربط الدفع بالطلب.

### 12.2 Related Use Cases
- UC-07 Pay Fees


<!-- Page 20 -->

### 12.3 Related Business Rules
- BR-10 Payment Required
- BR-11 Prevent Duplicate Payment
- BR-12 Failed Payment Handling
- BR-37 Do Not Store Sensitive Payment Data
- BR-45 Payment Status Modification Control
- BR-52 Fees Are Defined by System

### 12.4 Swimlanes
Swimlane
Activities
Citizen
Select fee, initiate payment
System
Calculate fees, create payment request, verify result
Payment Gateway
Process payment
Notification Service Notify user

### 12.5 Step-by-Step Flow
Start
The flow starts when the citizen opens the payment page.

Citizen
1. Open Payment Page.
2. Select Fee To Pay.


<!-- Page 21 -->

System
3. Calculate Required Fees.
4. Check if fee is already paid.

Decision 1
Is Fee Already Paid?
- Yes:
1. Show Already Paid Message.
2. End.
- No:
Continue.

System
5. Create Payment Request.
6. Send Payment Request to Payment Gateway.

Payment Gateway
7. Process Payment.
8.
Decision 2
Is Payment Successful?
- No:
1. Payment Gateway returns failure response.
2. System sets payment status to Failed.
3. System keeps application status as Payment Pending.
4. System shows payment failure message.

<!-- Page 22 -->

5. Citizen may retry payment.
- Yes:
1. Payment Gateway returns success response.
2. System verifies payment amount and reference number.

Decision 3
Is Payment Amount Valid?
- No:
1. System sets payment status to Under Verification.
2. System notifies Admin or Employee.
3. End.
- Yes:
Continue.

System
8. Record payment.
9. Set payment status to Completed.
10. Update application status to next stage.

Notification Service
11. Send payment confirmation.

End
The flow ends after successful payment confirmation.


<!-- Page 23 -->

### 12.6 Notes for Drawing AD-03
- Payment Gateway must be an external swimlane.
- Do not show card details being stored in the system.
- Show failed payment path clearly.
- Show duplicate payment prevention.

13. Diagram AD-04
Testing Flow

### 13.1 Purpose
يوضح هذا المخطط مسار االختبارات المتسلسل، مع حاالت النجاح، الرسوب، عدم الحضور، وإعادة االختبار.

### 13.2 Related Use Cases
- UC-08 Book Test Appointment
- UC-09 Reschedule / Cancel Appointment
- UC-10 View Test Results
- UC-11 Retake Failed Test
- UC-12 Record Test Result

### 13.3 Related Business Rules
- BR-13 Test Sequence
- BR-14 Prevent Test Skipping
- BR-15 Retake Same Failed Test
- BR-16 Attempts Limit
- BR-17 Appointment Booking Conditions
- BR-18 Prevent Full Appointment Booking
- BR-19 Prevent Duplicate Active Appointment

<!-- Page 24 -->

- BR-21 Only Authorized Employee Can Record Result
- BR-22 Result Updates Application Status
- BR-44 Result Modification Control
- BR-50 Appointment Expiration
- BR-51 No Show Handling

### 13.4 Swimlanes
Swimlane
Activities
Citizen
Request appointment, attend test, retake test
System
Determine available test, validate appointment, update status
Employee
Record test result
Notification Service Send notifications

### 13.5 Step-by-Step Flow
Start
The flow starts when the application reaches testing stage.

System
1. Get current application stage.
2. Determine available test.
Available tests follow this sequence:
1. Vision Test
2. Theory Test
3. Practical Test


<!-- Page 25 -->

Citizen
3. Request Test Appointment.

System
4. Check appointment availability.
5. Check no active appointment exists for the same test.
6. Check previous test requirement.

Decision 1
Is Appointment Available?
- No:
1. System shows alternative appointment slots.
2. Citizen selects another appointment.
3. Return to availability check.
- Yes:
Continue.

System
7. Book Appointment.
8. Set appointment status to Booked.

Notification Service
9. Send appointment confirmation.


<!-- Page 26 -->

Employee
10. Open test appointment.
11. Record test result:
- Passed
- Failed
- No Show

Decision 2
Did Citizen Attend?
- No:
1. System sets result to No Show.
2. System applies no-show policy.
3. System allows new appointment if policy permits.
4. End or return to Request Test Appointment.
- Yes:
Continue.

Decision 3
Did Citizen Pass?
- No:
1. System increments attempt count.
2. System checks attempts limit.


<!-- Page 27 -->

Decision 4
Attempts Limit Reached?
- Yes:
1. System sends application to administrative review.
2. Notification Service notifies Citizen.
3. End.
- No:
1. System sets application status to Waiting Retest.
2. Citizen books retake for the same failed test only.
3. Return to Request Test Appointment.

If Passed
1. System records result as Passed.
2. System checks next required test.

Decision 5
Is There a Next Test?
- Yes:
1. System unlocks next test.
2. Citizen books next test.
3. Return to Request Test Appointment.
- No:
1. System sets application status to Approved.
2. Notification Service notifies Citizen.
3. End.


<!-- Page 28 -->

### 13.6 Notes for Drawing AD-04
- This is one of the most important diagrams.
- Do not allow Theory Test before Vision Test.
- Do not allow Practical Test before Theory Test.
- Failed test returns only to the same test.
- No Show is different from Failed.
- Attempts limit should appear as a decision.

14. Diagram AD-05
License Issuance Flow

### 14.1 Purpose
يوضح هذا المخطط عملية إصدار الرخصة بعد اكتمال جميع الشروط.

### 14.2 Related Use Cases
- UC-13 Issue License

### 14.3 Related Business Rules
- BR-23 License Issuance Conditions
- BR-24 Unique License Number
- BR-25 License Validity
- BR-32 Audit Log
- BR-53 Prevent Issuance With Active Fines


<!-- Page 29 -->

### 14.4 Swimlanes
Swimlane
Activities
Employee
Select ready application, initiate issuance
System
Verify conditions, generate license, update status
Notification Service Notify citizen

### 14.5 Step-by-Step Flow
Start
The flow starts when the employee opens ready-to-issue applications.

Employee
1. Open Ready-To-Issue Applications.
2. Select Application.
3. Click Issue License.

System
4. Verify documents are approved.
5. Verify payments are completed.
6. Verify all required tests are passed.
7. Verify no active blocking fines.
8. Verify license is not already issued.


<!-- Page 30 -->

Decision 1
Are All Conditions Completed?
- No:
1. System shows missing requirements.
2. System stops issuance.
3. End.
- Yes:
Continue.

System
9. Generate unique license number.

Decision 2
Is License Number Unique?
- No:
1. Generate another number.
2. Return to uniqueness check.
- Yes:
Continue.

System
10. Create license record.
11. Set issue date.
12. Set expiry date.
13. Set license status to Active.
14. Set application status to License Issued.
15. Add Audit Log entry.

<!-- Page 31 -->

Notification Service
16. Notify Citizen.

End
The flow ends after license issuance.

### 14.6 Notes for Drawing AD-05
- Employee does not manually create the license number.
- System must verify all conditions before issuance.
- Audit Log is mandatory.
- If any requirement is missing, issuance must stop.

15. Diagram AD-06
Other License Services Flow

### 15.1 Purpose
يوضح هذا المخطط المسار العام للخدمات األخرى غير إصدار رخصة جديدة.

الخدمات المشمولة:

- Renew License
- Request Lost Replacement
- Request Damaged Replacement
- Request License Unblock

### 15.2 Related Use Cases
- UC-15 Renew License
- UC-16 Request Lost/Damaged Replacement
- UC-17 Request License Unblock

<!-- Page 32 -->

### 15.3 Related Business Rules
- BR-26 Renew License Conditions
- BR-27 Replacement Conditions
- BR-28 License Unblock Conditions
- BR-29 Fine Payment
- BR-43 Rejection Reason Required
- BR-52 System-Defined Fees
- BR-54 Old License Deactivation After Renewal

### 15.4 Swimlanes
Swimlane
Activities
Citizen
Select service, upload documents, pay fees
System
Check eligibility, calculate fees, execute service
Employee
Review request
Payment Gateway
Process payment
Notification Service Notify citizen

### 15.5 Step-by-Step Flow
Start
The flow starts when the citizen opens the services list.

Citizen
1. Open Services List.
2. Select Service Type:
- Renew License

<!-- Page 33 -->

- Lost Replacement
- Damaged Replacement
- License Unblock

System
3. Display Citizen Licenses.

Citizen
4. Select License.

System
5. Check service eligibility.

Decision 1
Is Service Allowed?
- No:
1. System shows rejection reason.
2. End.
- Yes:
Continue.

System
6. Display required documents and fees.

Citizen
7. Upload Required Documents.

<!-- Page 34 -->

System
8. Validate documents.
9. Set request status to Under Review.

Employee
10. Review Request.

Decision 2
Is Request Approved?
- No:
1. Employee enters rejection reason.
2. System sets request status to Rejected.
3. Notification Service notifies Citizen.
4. End.
- Yes:
Continue.

Citizen
11. Pay Required Fees.

Payment Gateway
12. Process Payment.


<!-- Page 35 -->

Decision 3
Is Payment Successful?
- No:
1. System keeps status as Payment Pending.
2. Citizen retries payment.
- Yes:
Continue.

System
13. Execute Service Action.
The action depends on selected service:
Service
System Action
Renew License
Create renewed license and deactivate old license
Lost Replacement
Create replacement license copy
Damaged Replacement Create replacement license copy
License Unblock
Change license status to Active

Notification Service
14. Notify Citizen.

End
The flow ends after service execution.


<!-- Page 36 -->

### 15.6 Notes for Drawing AD-06
- This diagram is general, not too detailed.
- Do not draw four separate diagrams unless required.
- Use a decision node for service type.
- Each service action can be represented as one final action.

16. Relationship Between Activity Diagrams and Use Cases
Activity Diagram
Related Use Cases

## AD-01 New License
Application Flow
UC-02, UC-03, UC-04, UC-05, UC-06, UC-07, UC-08, UC-10,
UC-11, UC-12, UC-13, UC-14

## AD-02 Document Review
Flow
UC-05, UC-06

## AD-03 Payment Flow

## UC-07

## AD-04 Testing Flow
UC-08, UC-09, UC-10, UC-11, UC-12

## AD-05 License Issuance
Flow

## UC-13

## AD-06 Other License
Services Flow
UC-15, UC-16, UC-17

17. Relationship Between Activity Diagrams and SRS Features
SRS Feature
Covered By
Authentication and Account Management AD-01
License Application Management
AD-01
Document Management
AD-01, AD-02
Payment Management
AD-01, AD-03

<!-- Page 37 -->

SRS Feature
Covered By
Test Appointment Management
AD-01, AD-04
Test Result Management
AD-01, AD-04
License Issuance
AD-01, AD-05
Other License Services
AD-06
Notifications

## AD-01 to AD-06
RBAC
AD-02, AD-04, AD-05, AD-06

18. Drawing Plan for the Team
Step 1: Prepare draw.io File
Create one “.drawio” file containing all diagrams, or create separate files for each diagram.
Recommended:
- One file for all diagrams.
- Each diagram on a separate page.
Pages:

## AD-01 New License Application Flow

## AD-02 Document Review Flow

## AD-03 Payment Flow

## AD-04 Testing Flow

## AD-05 License Issuance Flow

## AD-06 Other License Services Flow


<!-- Page 38 -->

Step 2: Create Swimlanes
For each diagram, create swimlanes according to the tables provided.
Example for AD-01:
- Citizen
- System
- Employee
- Payment Gateway
- Notification Service

Step 3: Add Start Node
Place Start Node in the lane where the process begins.
Usually:
- Citizen lane for service flows.
- Employee lane for administrative flows.

Step 4: Add Activities in Order
Use the step-by-step flows in this report.
Each numbered step becomes one activity box.

Step 5: Add Decision Nodes
Convert each question into a diamond.
Examples:
- Is Profile Complete?
- Are Documents Valid?
- Is Payment Successful?
- Did Citizen Pass?

<!-- Page 39 -->

Step 6: Label Branches
Every decision branch must be labeled:
- Yes / No
- Success / Failed
- Accepted / Rejected

Step 7: Add Loops
Some flows return to earlier steps:
- Rejected documents → Re-upload documents.
- Failed payment → Retry payment.
- Failed test → Retake same test.
- Full appointment → Select another appointment.

Step 8: Add Final Node
Every flow must end with a Final Node.

Step 9: Review Consistency
Check:
- Does the diagram match Use Cases?
- Does it follow Business Rules?
- Are statuses correct?
- Are swimlanes correct?
- Are all decisions labeled?


<!-- Page 40 -->

Step 10: Export
Export each diagram as:
- PNG for quick viewing.
- PDF or SVG for final report.

19. Team Task Distribution

……..
Reviewer
One person should review all diagrams for consistency.
Reviewer checks:
- Names.
- Statuses.
- Decisions.
- Arrows.
- Swimlanes.
- Alignment with SRS and Business Rules.

20. Quality Checklist
Before accepting any Activity Diagram, verify the following:
Structure
- Has Start Node.
- Has Final Node.
- Uses correct swimlanes.
- Has no isolated activity.
- Every activity has input and output arrows.

<!-- Page 41 -->

Logic
- No skipped business rule.
- No impossible transition.
- No missing alternative path.
- No direct license issuance before payment and tests.
- No theory test before vision test.
- No practical test before theory test.
Naming
- Activity names are clear.
- Decision names are written as questions.
- Branches are labeled.
- Status names match approved list.
Scope
- No API details.
- No database tables.
- No programming language references.
- No excessive UI details.
Visual Quality
- Arrows are readable.
- Lines do not cross too much.
- Layout is balanced.
- Diagram fits one page if possible.


<!-- Page 42 -->

21. Common Mistakes to Avoid
1. Drawing Use Case Diagram instead of Activity Diagram.
2. Putting actors outside instead of using swimlanes.
3. Drawing database tables.
4. Showing API calls.
5. Forgetting decision branch labels.
6. Allowing user to skip test sequence.
7. Issuing license before completing conditions.
8. Treating document upload as approval.
9. Treating no-show as failed without policy.
10. Creating too many unnecessary diagrams.

22. Final Recommendation
The team should produce exactly these six diagrams:
1. AD-01 New License Application Flow
2. AD-02 Document Review Flow
3. AD-03 Payment Flow
4. AD-04 Testing Flow
5. AD-05 License Issuance Flow
6. AD-06 Other License Services Flow
The most important diagram is AD-01, and it should be drawn first.
The other diagrams are supporting diagrams that explain the complex parts in more detail.
Once all Activity Diagrams are completed, the next phase will be:


<!-- Page 43 -->

Sequence Diagrams, because they will convert these workflows into interactions
between:
- Mobile App
- Backend API
- Services
- Database
- External Gateways

23. Final Deliverables Required from the Team
The team must submit:
1. AD-01_New_License_Application_Flow.drawio
2. AD-02_Document_Review_Flow.drawio
3. AD-03_Payment_Flow.drawio
4. AD-04_Testing_Flow.drawio
5. AD-05_License_Issuance_Flow.drawio
6. AD-06_Other_License_Services_Flow.drawio
And exported images:
1. AD-01_New_License_Application_Flow.png
2. AD-02_Document_Review_Flow.png
3. AD-03_Payment_Flow.png
4. AD-04_Testing_Flow.png
5. AD-05_License_Issuance_Flow.png
6. AD-06_Other_License_Services_Flow.png
