# One-off: second pass on zh_HK (6 Nov 2026). Writes zh_HK_final.py and updates T/P in zh_HK.py.
# Hong Kong school terms: 班別 (form group, as 1A), 科目班 (class, a teaching group; 班級 reads as the
# form class in Hong Kong), 年級, 課程, 課堂, 學期, 班主任, 教職員 (staff), 老師, 家長, 點名 / 出席,
# 家庭作業, 時間表, 計劃表, 成績冊 (markbook; 成績表 is a report card in HK), 成績報告, 社 (house), 電郵.
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('zhhk_cur.json', encoding='utf-8'))
NEW = {
 'Add Class': '新增科目班', 'Add Staff': '新增教職員', 'After End Date': '結束日期後', 'All Staff': '所有教職員', 'Attendance': '出席紀錄',
 'Biography': '個人簡介', 'CC Family?': '副本送交家庭？', 'Can Login?': '可登入？', 'Children': '子女', 'Choose A Class': '選擇科目班',
 'Class': '科目班', 'Class should not run below this number of students.': '科目班人數不應少於此數目。', 'Classes': '科目班',
 'Contact By Email': '以電郵聯絡', 'Contact By Phone': '以電話聯絡', 'Contact By SMS': '以短訊聯絡',
 'Controls what a user can do and see.': '控制用戶可進行的操作及可查看的內容。', 'Country Of Origin': '原居地', 'Country of Birth': '出生地',
 'County, State, District': '州／省、縣、區', 'Course Enrolment by Class': '按科目班的課程報名', 'Data Access?': '可存取資料？',
 'Descriptor': '描述', 'Do not contact by SMS.': '請勿以短訊聯絡。', 'Do not contact by email.': '請勿以電郵聯絡。', 'Do not contact by phone.': '請勿以電話聯絡。',
 'Due Date': '限期', 'Edit Class': '編輯科目班', 'Edit Staff': '編輯教職員', 'Email': '電郵', 'Email?': '電郵？', 'Employer': '僱主',
 'Homework + Due Dates': '家庭作業及限期', 'Home': '住宅', 'Include all staff, regardless of status, start date, end date, etc.': '包括所有教職員，不論狀態、開始日期、結束日期等。',
 'Initials': '姓名簡寫', 'Job Title': '職位', 'Manage Courses & Classes': '管理課程和科目班', 'Manage Staff': '管理教職員', 'Markbook': '成績冊',
 'Medical Conditions': '病況', 'Most common name, alias, nickname, etc.': '最常用的名字、別名、綽號等。', 'My Classes': '我的科目班',
 'Official Name': '正式姓名', 'Paid': '已付款', 'Partial': '部分出席', 'Personal': '個人資料', 'Preferred Name': '慣用名',
 'Qualifications': '學歷及資格', 'Reports': '成績報告', 'Response': '處理方法', 'Schedule': '日程', 'School Closed': '停課', 'School Closure': '停課日',
 'Selected': '已選', 'Should the family be sent a copy of billing emails?': '是否將帳單電郵副本發送給家庭？', 'Staff': '教職員',
 'Staff Directory': '教職員名錄', 'Student ID': '學生編號', 'Support': '非教學', 'Surname': '姓氏',
 'There are no lessons for this class.': '此科目班沒有課堂。', 'Timing Change': '時間調動', 'Title': '稱謂', 'Update Staff Data': '更新教職員資料',
 'Work': '辦公室', 'You do not have access to this action.': '你沒有權限執行此操作。', 'Teachers': '老師',
 'Mon': '週一', 'Tue': '週二', 'Wed': '週三', 'Thu': '週四', 'Fri': '週五', 'Sat': '週六', 'Sun': '週日',
 'Teacher': '老師', 'Assistant': '助教', 'Support Staff': '非教學人員', 'Expected': '預計加入', 'Admissions': '入學申請', 'Finance': '財務',
 'Crowd Assessment': '群體評估', 'Messenger': '訊息發送', 'Individual Needs': '個別需要', 'Tracking': '學習追蹤',
}
WHY = {
 'Class': '課堂 (a lesson); 班級 reads as the form class in Hong Kong', 'Markbook': '成績表 (a report card in Hong Kong)',
 'Teacher': 'missing (plural entry only)', 'Staff': '員工 (company employees); schools say 教職員', 'Admissions': '申請部 (an applications department)',
 'Finance': '財經 (financial news)', 'Crowd Assessment': '外部評估 (external assessment)', 'Messenger': '信息, close to Messages 訊息',
 'Title': '標題 (a heading); Mr / Mrs', 'Attendance': '出席表 (an attendance sheet)', 'Home': '主頁 (home page); phone type',
 'Work': '作業 (homework); phone type', 'Schedule': '時間表, same as Timetable', 'Preferred Name': '首選名稱 (a "first-choice name")',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('zh_HK_final.py', 'w', encoding='utf-8') as f:
    f.write("# zh_HK: every Gibbon string the app uses, as the app should show it (line-by-line read, 6 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's zh_HK file.\n"
            "# 班別 (form group), 科目班 (class), 年級, 課程, 課堂, 學期, 班主任, 教職員, 老師, 出席, 點名, 成績冊, 成績報告, 社, 電郵.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r}," + (f"   # Gibbon: {WHY[k]}" if k in WHY else '') + "\n")
    f.write("}\n")
import zh_HK as F
R = [('班級缺席', '課堂缺席'), ('班級', '科目班'), ('員工', '教職員'), ('成績表', '成績冊'), ('電子郵件', '電郵')]
def fix(s):
    for a, b in R: s = s.replace(a, b)
    return s
T = {k: fix(v) for k, v in F.T.items()}
P = {k: tuple(fix(x) for x in v) for k, v in F.P.items()}
T['Staff Profile'] = '教職員檔案'; T['Browse staff profiles'] = '瀏覽教職員檔案'
s = open('zh_HK.py', encoding='utf-8').read()
s = re.sub(r'^T = \{.*?\n(?=P = )', lambda m: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda m: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = s.replace("# No plural forms in Chinese", "# Second pass 6 Nov 2026 (zh_HK_2nd.py): 科目班 for classes, 教職員, 成績冊, 電郵, 慣用名.\n# No plural forms in Chinese")
open('zh_HK.py', 'w', encoding='utf-8').write(s)
