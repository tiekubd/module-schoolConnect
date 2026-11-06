# One-off: second pass on zh_CN (6 Nov 2026). Writes zh_CN_final.py and rewrites T/P in zh_CN.py.
# zh_CN is maintained by hand from now on (make_zh_CN.py would undo this).
import json, pprint, re, sys
sys.path.insert(0, '.')
cur = json.load(open('zh_cur.json', encoding='utf-8'))
NEW = {
 'Absent': '缺勤', "Access data on family's children?": '可访问家庭中子女的信息？',
 'Add Class': '添加教学班', 'Add Medical Condition': '添加病况', 'Add Special Day': '添加特殊日', 'Add Staff': '添加教职工',
 'Adult Family Members': '家庭中的成年成员', 'Alternate Email': '备用电子邮箱', 'Background Information': '背景信息',
 'Before Start Date': '开始日期前', 'Biography': '个人简介', 'By Form Group': '按班级', 'CC Family?': '抄送家庭？',
 'Call?': '可否致电？', 'Can Login?': '可否登录？', 'Choose A Class': '选择教学班', 'Class': '教学班',
 'Class Of': '毕业届别', 'Class should not run below this number of students.': '教学班人数不应少于此数。',
 'Classes': '教学班', 'Comment': '评语', 'Company All?': '公司支付全部费用？', 'Company Name': '公司名称',
 'Contact Information': '联系方式', 'Contact Priority': '联系优先级', 'County, State, District': '省、市、区',
 'Course Enrolment by Class': '按教学班注册课程', 'Course Enrolment by Person': '按个人注册课程',
 'Data Access?': '可访问数据？', 'Data Updater': '信息更新', 'Data Updates': '信息更新',
 'Data displayed in full Student Profile': '在完整学生档案中显示的数据', 'Days of the Week': '星期',
 'Descriptor': '描述词', 'Details': '详情', 'Due on {date} at {time}.': '截止时间：{date} {time}。',
 'Edit Class': '编辑教学班', 'Edit Staff': '编辑教职工', 'Employer': '工作单位',
 'Enrolment should not exceed this number of students.': '注册人数不应超过此数。', 'Ethnicity': '民族',
 'Exclude from coverage?': '不参与代课？', 'Family name as shown in ID documents.': '身份证件上的姓氏。',
 'Fax': '传真', 'First Aid Expiry': '急救证书到期日', 'First name as shown in ID documents.': '身份证件上的名字。',
 'Follow Up': '跟进', 'Form Group': '班级', 'Full name as shown in ID documents.': '身份证件上的全名。',
 'Higher priority substitutes appear first when booking coverage.': '安排代课时，优先级较高的代课教师排在前面。',
 'Home': '家庭电话', 'Homework + Due Dates': '作业与截止日期', 'Homework Details': '作业详情',
 'In an emergency, please try and contact the adult family members listed below first. If these cannot be reached, then try the emergency contacts below.': '紧急情况下，请先联系下列家庭中的成年成员。如无法联系上，再联系下列紧急联系人。',
 'Include In Curriculum Map': '纳入课程地图',
 'Include all staff, regardless of status, start date, end date, etc.': '包括所有教职工，不论其状态、开始日期、结束日期等。',
 'Include all students, regardless of status and current enrolment. Some data may not display.': '包括所有学生，不论其状态和当前注册情况。部分数据可能无法显示。',
 'Initials': '姓名缩写', 'Invoice To': '开票对象', 'Issue Date': '开具日期', 'Job Title': '职位', 'Last': '最后',
 'Last School': '之前就读学校', 'Manage Courses & Classes': '管理课程和教学班', 'Manage Staff': '管理教职工',
 'Markbook': '成绩册', 'Medical Conditions': '病况', 'Medication Details': '用药详情', 'Mobile': '手机',
 'My Classes': '我的教学班', 'My Form Group': '我的班级', 'My Timetable': '我的课程表', 'Negative': '负面',
 'No upcoming due dates!': '近期没有截止日期！', 'Not Reportable': '不计入报告', 'Not Taken': '未考勤',
 'Number': '号码', 'Off Timetable': '课程表外', 'Official Name': '正式姓名', 'Pager': '传呼机',
 'Parent Dashboard': '家长主页', 'Partial': '部分出勤', 'Pay Now': '立即付款', 'Person': '人员', 'Personal': '个人信息',
 'Phone': '电话', 'Planner': '教学计划', 'Positive': '正面', 'Preferred Name': '常用名',
 'Privacy not required or not set.': '无需隐私保护或未设置。', 'Report': '成绩报告', 'Reportable': '计入报告',
 'Reportable?': '计入报告？', 'Reports': '成绩报告', 'Response': '处理方法', 'Schedule': '日程',
 'School Closed': '停课', 'School Closure': '停课日', 'School Day': '上课日',
 'Should the family be sent a copy of billing emails?': '是否同时向家庭发送账单邮件副本？',
 'Should this student be automatically enrolled in courses for their Form Group?': '是否自动为该学生注册其班级的课程？',
 'Siblings': '兄弟姐妹', 'Staff': '教职工', 'Staff Directory': '教职工名录', 'Student ID': '学生编号',
 'Student Recorded Homework': '学生自记作业', 'Support': '后勤', 'System Access': '系统权限',
 'Take Attendance': '记录考勤', 'Teaching': '教学', 'There are no lessons for this class.': '此教学班没有课堂。',
 'There are no lessons on this date.': '当天没有课。', 'This lesson has not had any content assigned to it.': '此课堂尚未添加内容。',
 'Timing Change': '时间调整', 'Track Attendance?': '记录考勤？', 'Tutor': '班主任',
 'Unit, Building, Street': '门牌、楼栋、街道', 'Up to date': '已是最新',
 'Update Family Data': '更新家庭信息', 'Update Finance Data': '更新财务信息', 'Update Medical Data': '更新医疗信息',
 'Update Personal Data': '更新个人信息', 'Update Staff Data': '更新教职工信息', 'User Account': '用户账户',
 'User will be prompted on next login.': '用户将在下次登录时收到提示。', 'View Planner': '查看教学计划',
 'View Reports': '查看成绩报告', 'Your active session will be switched to the selected user.': '您当前的会话将切换至所选用户。',
 'Mon': '周一', 'Tue': '周二', 'Wed': '周三', 'Thu': '周四', 'Fri': '周五', 'Sat': '周六', 'Sun': '周日',
 'Teacher': '教师', 'Assistant': '助教', 'Expected': '待加入', 'Tracking': '学业追踪',
 'Cancel Activities?': '取消活动？', 'Name In Characters': '中文姓名', 'Receipt To': '收据抬头',
}
WHY = {  # what Gibbon had, where it matters
 'Form Group': '小组 (a small group); a mainland homeroom class is the 班级', 'Class': '班级 (now the form group); teaching class = 教学班',
 'Tutor': '导师; the mainland homeroom teacher is the 班主任', 'Staff': '员工 (company employees); schools say 教职工',
 'Planner': '计划 here, 计划表 in the app', 'Markbook': '成绩单 (same as a report card)', 'Home': '主页 (home page); phone type',
 'Teacher': 'missing (plural entry only)', 'School Closed': '学校关闭, same as School Closure', 'Ethnicity': '种族 (race)',
 'Absent': '缺课; pairs with 出勤', 'Homework + Due Dates': '家庭作业 + 截止日期 (with a literal +)', 'Expected': '预期 (an expectation)',
 'Preferred Name': '英文名 (English name)', 'Mon': '星期一, the full name, for the short form',
}
FINAL = {k: NEW.get(k, v) for k, v in cur.items()}
assert set(NEW) <= set(FINAL), set(NEW) - set(FINAL)
with open('zh_CN_final.py', 'w', encoding='utf-8') as f:
    f.write("# zh_CN: every Gibbon string the app uses, as the app should show it (line-by-line read, 6 Nov 2026),\n"
            "# plus the database values in dyn.py. split_final.py compares with Gibbon's zh_CN file.\n"
            "# Mainland school terms: 班级 (form group), 教学班 (class), 年级, 课程, 课堂, 学期, 班主任, 教职工, 考勤,\n"
            "# 课程表, 教学计划, 成绩册 (markbook), 成绩报告 (report card); 您 in sentences.\nFINAL = {\n")
    for k, v in FINAL.items():
        f.write(f"{k!r}: {v!r},")
        if k in WHY: f.write(f"   # Gibbon: {WHY[k]}")
        f.write("\n")
    f.write("}\n")
# App-only strings: mainland wording
import zh_CN as F
R = [('班级缺勤', '课堂缺勤'), ('班级', '教学班'), ('小组', '班级'), ('搜寻', '搜索'), ('设定', '设置'), ('尚余', '剩余'), ('尚有', '还有'),
     ('新增', '添加'), ('标示', '标记'), ('连结', '关联'), ('相容', '兼容'), ('透过', '通过'), ('加密连线', '加密连接'), ('影片', '视频'),
     ('产生', '生成'), ('付款闸道', '支付渠道'), ('栏位', '字段'), ('当值', '值班'), ('惯用名称', '常用名'), ('提早', '提前'),
     ('寄出', '发送'), ('登出', '退出登录'), ('过往', '以往'), ('令课程表', '使课程表'), ('次序', '顺序'), ('原居国家', '原籍国'),
     ('员工', '教职工'), ('只限', '仅限'), ('未有记录', '没有记录'), ('未有提供', '未提供'), ('目前未有注册', '目前未注册'),
     ('请电子邮件至', '请发送邮件至'), ('查询', '疑问'), ('数据保护主任', '数据保护负责人'), ('计划表', '教学计划'), ('成绩单', '成绩册'),
     ('课堂计划', '教学计划'), ('出席', '考勤'), ('暂时', '暂'), ('你', '您'), ('及', '和'), ('亦', '也')]
FIX = {  # after the replacements
 'Browse staff profiles': '浏览教职工档案', 'Search and view student profiles': '搜索和查看学生档案', 'Staff Profile': '教职工档案',
 'Permanently delete "{name}" and all of its classes?': '永久删除“{name}”及其所有教学班？',
 'Trial — {count} day left': ('试用期 — 剩余 {count} 天',), 'Trial access': '试用期',
 'Could not load family data': '无法加载家庭信息', 'Could not load finance data': '无法加载财务信息',
 'Could not load medical data': '无法加载医疗信息', 'Could not load staff data': '无法加载教职工信息',
 'Could not save for: {names}': '以下人员无法保存：{names}',
 'Saved for {count}, but failed for: {names}': ('已为 {count} 人保存，以下人员保存失败：{names}',),
 'Gateway': '支付渠道', 'Cap': '容量', 'Roll': '学号',
 'Choose a class above': '请在上方选择教学班', 'No class': '没有课',
 '{count} class': ('{count} 个教学班',), 'Tutor 2': '班主任 2',
 'Lesson planning is coming soon': '教学计划功能即将推出',
 'Content in lessons: videos or images a teacher adds (for example YouTube) load from those sites when you open them': '课堂内容：教师添加的视频或图片（例如 YouTube）会在您打开时从相应网站加载',
 "Highlighted in red: marked 'Full' but outside their start/end date range.": '红色标记：状态为“正式”，但不在开始／结束日期范围内。',
 'Behaviour record saved!': '已保存表现记录！',
}
def fix(s):
    for a, b in R:
        s = s.replace(a, b)
    return s.replace('和其', '及其').replace('以和', '以及')
T = {k: fix(v) for k, v in F.T.items()}
P = {k: tuple(fix(x) for x in v) for k, v in F.P.items()}
for k, v in FIX.items():
    (P if isinstance(v, tuple) else T)[k] = v
s = open('zh_CN.py', encoding='utf-8').read()
s = re.sub(r'^T = \{.*?\n(?=P = )', lambda m: 'T = ' + pprint.pformat(T, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = re.sub(r'^P = \{.*?\n(?=EXTRA = )', lambda m: 'P = ' + pprint.pformat(P, width=200, sort_dicts=False) + '\n\n', s, count=1, flags=re.S | re.M)
s = s.replace("'Present - Late': '出勤 - 迟到'", "'Present - Late': '出勤 - 迟到'")
open('zh_CN.py', 'w', encoding='utf-8').write(s)
