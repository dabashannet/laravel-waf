<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>访问被拦截 - 安全防护</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #333;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 48px 40px;
            max-width: 480px;
            width: 90%;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .icon { font-size: 64px; margin-bottom: 20px; display: block; }
        h1 { font-size: 22px; font-weight: 600; margin-bottom: 12px; color: #1a1a1a; }
        p { font-size: 15px; color: #666; line-height: 1.6; margin-bottom: 24px; }
        .status-code {
            display: inline-block;
            background: #fee;
            color: #c00;
            font-size: 13px;
            padding: 4px 12px;
            border-radius: 4px;
            font-family: monospace;
        }
        .back-link {
            margin-top: 24px;
            display: block;
            color: #007aff;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <span class="icon">🛡️</span>
        <h1>请求已被安全策略拦截</h1>
        <p>{{ $message ?? '您的请求触发了安全防护规则，已被系统自动拦截。如认为这是误报，请联系网站管理员。' }}</p>
        <span class="status-code">HTTP {{ $status ?? 403 }}</span>
        <a href="javascript:history.back()" class="back-link">← 返回上一页</a>
    </div>
</body>
</html>
