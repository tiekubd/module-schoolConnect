# Builds zh_CN.py from zh_HK.py: OpenCC hk2s, then mainland vocabulary and Gibbon zh_CN's own terms.
import sys, re, importlib, pprint
sys.path.insert(0, '.')
from opencc import OpenCC
cc = OpenCC('hk2s')
HK = importlib.import_module('zh_HK')
REPL = [  # order matters: longer phrases first
 ('为今天点名', '记录今天的考勤'), ('为此班级点名', '记录此班级的考勤'), ('今天尚未点名', '今天尚未考勤'), ('已点名', '已考勤'), ('未点名', '未考勤'), ('点名', '考勤'),
 ('查看出席', '查看考勤'), ('最近出席', '最近考勤'), ('本年出席', '本年考勤'), ('出席纪录', '考勤记录'), ('班级缺席', '班级缺勤'),
 ('出席 - 迟到', '出勤 - 迟到'), ('出席 - 校外', '出勤 - 校外'),
 ('班别', '小组'), ('家庭作业', '作业'), ('总时间表', '总课程表'), ('场地时间表', '教室课程表'), ('时间表', '课程表'), ('计划表', '计划'), ('成绩表', '成绩单'),
 ('场地', '教室'), ('课室', '教室'), ('行为纪录', '表现记录'), ('记录行为', '记录表现'), ('行为', '表现'), ('报名', '注册'), ('班主任', '导师'), ('老师', '教师'),
 ('联络资料', '联系方式'), ('个人资料', '个人信息'), ('资料', '数据'), ('登入', '登录'), ('储存', '保存'), ('装置', '设备'), ('电邮', '电子邮件'),
 ('用户名称', '用户名'), ('帐户', '账户'), ('网上课堂', '在线课堂'), ('网上课室', '在线教室'), ('视像', '视频'), ('讯息', '消息'), ('私隐', '隐私'), ('联络', '联系'),
 ('按此编辑', '点击编辑'), ('按此查看或修改', '点击查看或修改'), ('请按画面顶部', '请点击屏幕顶部'), ('按 School Connect 旁的「更新」', '点击 School Connect 旁边的「更新」'),
 ('画面', '屏幕'), ('剔选此方格', '勾选此复选框'), ('程式', '程序'), ('相片', '照片'), ('支援', '支持'), ('预设', '默认'), ('伺服器', '服务器'), ('模组', '模块'),
 ('字元', '字符'), ('下拉选单', '下拉菜单'), ('疑难排解', '故障排除'), ('工作阶段', '会话'), ('纪录', '记录'), ('主页', '首页'), ('家长首页', '家长面板'),
 ('身分', '身份'), ('解除安装', '卸载'), ('当机', '崩溃'), ('推送通知', '推送通知'), ('合资格', '合格'), ('学号', '学号'),
 ('网上教室', '在线教室'), ('载入', '加载'), ('关于及隐私', '关于与隐私'), ('出席日数', '出勤天数'), ('缺席日数', '缺勤天数'), ('人缺席', '人缺勤'),
 ('一项或以上', '一项或多项'), ('或以上版本', '或更高版本'), ('家庭作业限期', '作业截止'), ('作业限期', '作业截止'), ('限期', '截止日期'), ('最少', '至少'), ('相连的', '关联的'), ('编排', '安排'), ('日数', '天数'),
]
def conv(s):
    s = cc.convert(s)
    for a, b in REPL: s = s.replace(a, b)
    return s.replace('「', '“').replace('」', '”')
T = {k: conv(v) for k, v in HK.T.items()}
P = {k: tuple(conv(x) for x in v) for k, v in HK.P.items()}
EXTRA = {k: conv(v) for k, v in HK.EXTRA.items()}
GAPS = {k: conv(HK.GAPS[k]) for k in ['Impersonate User', 'Layers', 'This student has one or more {level} risk medical conditions.', 'Your active session will be switched to the selected user.']}
T['Dashboard'] = '首页'
with open('zh_CN.py', 'w', encoding='utf-8') as f:
    f.write('# Simplified Chinese (zh_CN) draft, generated from zh_HK.py by make_zh_CN.py:\n# OpenCC hk2s, then mainland vocabulary and Gibbon zh_CN terms (小组, 作业, 课程表,\n# 考勤, 成绩单, 教室, 导师, 表现, 注册, 数据, 登录, 保存). Review before release.\n\n')
    for name, val in [('T', T), ('P', P), ('EXTRA', EXTRA), ('GAPS', GAPS), ('OVERRIDES', {})]:
        f.write(f'{name} = ' + pprint.pformat(val, width=200, sort_dicts=True) + '\n\n')
print('written', len(T), len(P))
