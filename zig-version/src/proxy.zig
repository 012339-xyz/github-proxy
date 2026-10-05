const std = @import("std");

const Config = struct {
    main: []const u8,
    assets: []const u8,
    raw: []const u8,
    objects: []const u8,
    avatars: []const u8,
    gist: []const u8,
    user_images: []const u8,
    api: ?[]const u8 = null,
    upstream: []const u8,
    port: u16,
    banned_zon: []const u8,
    inject_js: bool,
    inject_js_path: []const u8,
};

var inject_js: []const u8 = undefined;
var config: Config = undefined;
var banned_list: []const []const u8 = undefined;

fn loadConfig(scratch: std.mem.Allocator, arena: std.mem.Allocator, io: std.Io) !void {
    const config_file = try std.Io.Dir.cwd().openFile(io, "config.zon", .{});
    defer config_file.close(io);

    const config_file_length = try config_file.length(io);
    const config_file_content = try scratch.alloc(u8, config_file_length + 1);
    defer scratch.free(config_file_content);

    _ = try config_file.readPositionalAll(io, config_file_content, 0);
    config_file_content[config_file_length] = 0;

    var diagnostics: std.zon.parse.Diagnostics = undefined;
    config = std.zon.parse.fromSlice(Config, .{
        .gpa = scratch,
        .arena = arena,
        .source = config_file_content[0..config_file_length :0],
        .diagnostics = &diagnostics,
    }) catch |err| {
        if (err == error.ParseZon) diagnostics.log("config.zon");
        return err;
    };
}

fn loadBannedList(scratch: std.mem.Allocator, arena: std.mem.Allocator, io: std.Io) !void {
    const banned_list_file = try std.Io.Dir.cwd().openFile(io, config.banned_zon, .{});
    defer banned_list_file.close(io);

    const banned_list_file_length = try banned_list_file.length(io);
    const banned_list_file_content = try scratch.alloc(u8, banned_list_file_length + 1);
    defer scratch.free(banned_list_file_content);

    _ = try banned_list_file.readPositionalAll(io, banned_list_file_content, 0);
    banned_list_file_content[banned_list_file_length] = 0;

    var diagnostics: std.zon.parse.Diagnostics = undefined;
    banned_list = std.zon.parse.fromSlice([]const []const u8, .{
        .gpa = scratch,
        .arena = arena,
        .source = banned_list_file_content[0..banned_list_file_length :0],
        .diagnostics = &diagnostics,
    }) catch |err| {
        if (err == error.ParseZon) diagnostics.log(config.banned_zon);
        return err;
    };
}

fn hasPrefixCI(s: []const u8, prefix: []const u8) bool {
    if (s.len < prefix.len) return false;
    return std.ascii.eqlIgnoreCase(s[0..prefix.len], prefix);
}

fn indexOfCI(haystack: []const u8, needle: []const u8) ?usize {
    if (needle.len == 0) return 0;
    if (haystack.len < needle.len) return null;
    var i: usize = 0;
    while (i + needle.len <= haystack.len) : (i += 1) {
        if (std.ascii.eqlIgnoreCase(haystack[i .. i + needle.len], needle)) return i;
    }
    return null;
}

fn findCi(haystack: []const u8, needle: []const u8) ?usize {
    return indexOfCI(haystack, needle);
}

fn shouldRewriteBody(content_type: ?[]const u8) bool {
    const ct = content_type orelse return false;
    if (hasPrefixCI(ct, "text/html")) return true;
    if (hasPrefixCI(ct, "application/xhtml+xml")) return true;
    if (hasPrefixCI(ct, "text/css")) return true;
    if (hasPrefixCI(ct, "text/javascript")) return true;
    if (hasPrefixCI(ct, "application/javascript")) return true;
    if (hasPrefixCI(ct, "text/xml")) return true;
    if (hasPrefixCI(ct, "text/plain")) return false;
    if (indexOfCI(ct, "json") != null) return true;
    if (indexOfCI(ct, "+xml") != null) return true;
    return false;
}

fn isHtmlContentType(content_type: ?[]const u8) bool {
    const ct = content_type orelse return false;
    return hasPrefixCI(ct, "text/html") or hasPrefixCI(ct, "application/xhtml+xml");
}

fn bannedMatches(path: []const u8, pattern: []const u8) bool {
    const wildcard = pattern.len > 0 and pattern[pattern.len - 1] == '*';
    const p = if (wildcard) pattern[0 .. pattern.len - 1] else pattern;
    if (p.len == 0) return false;
    if (path.len < p.len) return false;
    if (!std.ascii.eqlIgnoreCase(path[0..p.len], p)) return false;
    if (wildcard) return true;
    if (path.len == p.len) return true;
    if (p.len <= 1) return false;
    return path[p.len] == '/';
}

fn isBannedPath(raw_path: []const u8) bool {
    var path = raw_path;
    while (path.len > 1 and path[path.len - 1] == '/') path = path[0 .. path.len - 1];
    for (banned_list) |pattern| {
        if (bannedMatches(path, pattern)) return true;
    }
    return false;
}

const HostMap = struct { host: []const u8, base: []const u8 };

fn hostMaps(buf: *[10]HostMap) []const HostMap {
    const pairs = [_]struct { []const u8, []const u8 }{
        .{ "github.githubassets.com", config.assets },
        .{ "raw.githubusercontent.com", config.raw },
        .{ "avatars.githubusercontent.com", config.avatars },
        .{ "objects.githubusercontent.com", config.objects },
        .{ "user-images.githubusercontent.com", config.user_images },
        .{ "gist.github.com", config.gist },
        .{ "api.github.com", config.api orelse "" },
        .{ "github.com", config.main },
    };
    var n: usize = 0;
    for (pairs) |p| {
        if (p[1].len == 0) continue;
        buf[n] = .{ .host = p[0], .base = p[1] };
        n += 1;
    }
    return buf[0..n];
}

fn isUrlBoundaryChar(c: u8) bool {
    return switch (c) {
        '/', '?', '#', '"', '\'', '<', '>', '(', ')', '[', ']', '{', '}', ',', ';', ' ', '\t', '\r', '\n' => true,
        else => false,
    };
}

fn hostLen(rest: []const u8) usize {
    var i: usize = 0;
    while (i < rest.len) : (i += 1) {
        if (isUrlBoundaryChar(rest[i])) return i;
    }
    return i;
}

fn emitUrls(writer: *std.Io.Writer, data: []const u8) !void {
    var maps_buf: [10]HostMap = undefined;
    const maps = hostMaps(&maps_buf);
    var index: usize = 0;
    while (std.mem.findPos(u8, data, index, "https://")) |found| {
        const after = found + 8;
        const rest = data[after..];
        const he = hostLen(rest);
        var mapped: ?[]const u8 = null;
        if (he > 0 and (after + he >= data.len or isUrlBoundaryChar(data[after + he]))) {
            for (maps) |m| {
                if (std.ascii.eqlIgnoreCase(rest[0..he], m.host)) {
                    mapped = m.base;
                    break;
                }
            }
        }
        if (mapped) |m| {
            _ = try writer.write(data[index..found]);
            _ = try writer.write(m);
            index = after + he;
            continue;
        }
        _ = try writer.write(data[index..after]);
        index = after;
        if (index >= data.len) return;
    }
    _ = try writer.write(data[index..]);
}

fn emitBody(writer: *std.Io.Writer, data: []const u8, rewrite: bool) !void {
    if (rewrite) {
        try emitUrls(writer, data);
    } else {
        _ = try writer.writeAll(data);
    }
}

fn urlHoldStart(data: []const u8) ?usize {
    const tag = "https://";
    var k: usize = @min(tag.len - 1, data.len);
    while (k >= 1) : (k -= 1) {
        if (std.mem.eql(u8, data[data.len - k ..], tag[0..k])) return data.len - k;
    }
    var last: ?usize = null;
    var idx: usize = 0;
    while (std.mem.findPos(u8, data, idx, "https://")) |f| {
        last = f;
        idx = f + 8;
    }
    const f = last orelse return null;
    const rest = data[f + 8 ..];
    const he = hostLen(rest);
    if (he >= 64) return null;
    if (he == rest.len) return f;
    return null;
}

fn mapLocation(out: []u8, location: []const u8) ?[]const u8 {
    const trimmed = std.mem.trim(u8, location, " \t\r\n");
    if (trimmed.len == 0) return null;
    if (trimmed[0] == '\\') return null;

    var scheme_len: usize = 0;
    if (hasPrefixCI(trimmed, "https://")) {
        scheme_len = 8;
    } else if (hasPrefixCI(trimmed, "http://")) {
        scheme_len = 7;
    } else if (std.mem.startsWith(u8, trimmed, "//")) {
        scheme_len = 0;
    } else {
        const lim = @min(trimmed.len, 64);
        if (std.mem.indexOfScalar(u8, trimmed[0..lim], ':')) |cpos| {
            if (std.mem.indexOfAny(u8, trimmed[0..cpos], "/?#") == null) return null;
        }
        if (trimmed.len > out.len) return null;
        @memcpy(out[0..trimmed.len], trimmed);
        return out[0..trimmed.len];
    }

    const rest = trimmed[scheme_len..];
    const he = hostLen(rest);
    const host = rest[0..he];
    var maps_buf: [10]HostMap = undefined;
    const maps = hostMaps(&maps_buf);
    for (maps) |m| {
        if (!std.ascii.eqlIgnoreCase(host, m.host)) continue;
        const tail = trimmed[scheme_len + he ..]; // 路径 + 查询
        if (m.base.len + tail.len > out.len) return null;
        @memcpy(out[0..m.base.len], m.base);
        @memcpy(out[m.base.len .. m.base.len + tail.len], tail);
        return out[0 .. m.base.len + tail.len];
    }
    return null;
}

fn rewriteHeaderValue(dst: []u8, value: []const u8) ?[]const u8 {
    var w = std.Io.Writer.fixed(dst);
    emitUrls(&w, value) catch return null;
    return w.buffered();
}

const ReqHeaders = struct {
    items: [8]std.http.Header = undefined,
    len: usize = 0,
    user_agent: ?[]const u8 = null,

    fn push(self: *ReqHeaders, name: []const u8, value: []const u8) void {
        if (self.len < self.items.len) {
            self.items[self.len] = .{ .name = name, .value = value };
            self.len += 1;
        }
    }
};

const DEFAULT_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36";

fn collectRequestHeaders(client_request: *std.http.Server.Request) ReqHeaders {
    var out: ReqHeaders = .{};
    var has_accept = false;
    var it = client_request.iterateHeaders();
    while (it.next()) |h| {
        if (std.ascii.eqlIgnoreCase(h.name, "accept")) {
            if (!std.mem.eql(u8, h.value, "*/*")) {
                out.push("accept", h.value);
                has_accept = true;
            }
        } else if (std.ascii.eqlIgnoreCase(h.name, "authorization")) {
            out.push("authorization", h.value);
        } else if (std.ascii.eqlIgnoreCase(h.name, "if-none-match")) {
            out.push("if-none-match", h.value);
        } else if (std.ascii.eqlIgnoreCase(h.name, "if-modified-since")) {
            out.push("if-modified-since", h.value);
        } else if (std.ascii.eqlIgnoreCase(h.name, "x-requested-with")) {
            out.push("x-requested-with", h.value);
        } else if (std.ascii.eqlIgnoreCase(h.name, "accept-language")) {
            out.push("accept-language", h.value);
        } else if (std.ascii.eqlIgnoreCase(h.name, "user-agent")) {
            out.user_agent = h.value;
        }
    }
    if (!has_accept) {
        out.push("accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8");
    }
    return out;
}

const SKIP_RESP_HEADERS = [_][]const u8{
    "transfer-encoding", "content-encoding",            "connection",                       "keep-alive",              "trailer",
    "content-length",    "location",                    "strict-transport-security",        "content-security-policy", "content-security-policy-report-only",
    "x-frame-options",   "access-control-allow-origin", "access-control-allow-credentials", "set-cookie",              "vary",
    "date",              "server",                      "alt-svc",                          "nel",                     "report-to",
};

fn collectResponseHeaders(out: []std.http.Header, head_bytes: []const u8, scratch: []u8) usize {
    var n: usize = 0;
    var link_scratch_used: usize = 0;
    var it = std.mem.splitSequence(u8, head_bytes, "\r\n");
    _ = it.first();
    while (it.next()) |line| {
        if (line.len == 0) break;
        const colon = std.mem.indexOfScalar(u8, line, ':') orelse continue;
        const name = std.mem.trim(u8, line[0..colon], " ");
        const value = std.mem.trim(u8, line[colon + 1 ..], " ");
        if (name.len == 0) continue;
        if (n >= out.len) break;
        var skipped = false;
        for (SKIP_RESP_HEADERS) |s| {
            if (std.ascii.eqlIgnoreCase(name, s)) {
                skipped = true;
                break;
            }
        }
        if (skipped) continue;
        if (std.ascii.eqlIgnoreCase(name, "link")) {
            const start = link_scratch_used;
            if (start + value.len <= scratch.len) {
                const dst = scratch[start .. start + value.len];
                if (rewriteHeaderValue(dst, value)) |rewritten| {
                    out[n] = .{ .name = name, .value = rewritten };
                    n += 1;
                    link_scratch_used = start + value.len;
                }
            }
            continue;
        }
        out[n] = .{ .name = name, .value = value };
        n += 1;
    }
    return n;
}
const BODY_CHUNK = 4096;
const HOLD_CAP = 1024;
const MAX_CONCURRENT_CONNS = 128;
const INJECT_SCAN_LIMIT = 16384;

fn headPrefixSuffixLen(data: []const u8) usize {
    const tag = "<head";
    var k: usize = @min(tag.len - 1, data.len);
    while (k > 0) : (k -= 1) {
        const suffix = data[data.len - k ..];
        var ok = true;
        for (suffix, 0..) |c, i| {
            if (std.ascii.toLower(c) != tag[i]) {
                ok = false;
                break;
            }
        }
        if (ok) return k;
    }
    return 0;
}

fn handleClientStream(
    io: std.Io,
    _allocator: std.mem.Allocator,
    upstream: *std.http.Client,
    client_stream: std.Io.net.Stream,
) anyerror!void {
    defer client_stream.close(io);

    var client_recv_buf: [8192]u8 = undefined;
    var client_send_buf: [4096]u8 = undefined;
    var client_body_buf: [4096]u8 = undefined;
    var upstream_recv_buf: [4096]u8 = undefined;
    var upstream_send_buf: [4096]u8 = undefined;
    var work: [BODY_CHUNK + HOLD_CAP]u8 = undefined;

    var client_reader = client_stream.reader(io, &client_recv_buf);
    var client_writer = client_stream.writer(io, &client_send_buf);
    var client_parser = std.http.Server.init(&client_reader.interface, &client_writer.interface);

    while (client_parser.reader.state == .ready) {
        var arena = std.heap.ArenaAllocator.init(_allocator);
        defer arena.deinit();
        const allocator = arena.allocator();

        var client_request = client_parser.receiveHead() catch |err| switch (err) {
            error.HttpConnectionClosing => {
                return;
            },
            else => {
                std.log.err("Read client request failed! {s}", .{@errorName(err)});
                return;
            },
        };

        switch (client_request.upgradeRequested()) {
            .none => {},
            .other, .websocket => {
                try client_request.respond("", .{ .status = .not_implemented, .reason = "Not Implemented" });
                continue;
            },
        }

        const real_request_path: []const u8 =
            if (std.mem.cut(u8, client_request.head.target, "?")) |rest|
                rest.@"0"
            else
                client_request.head.target;

        if (isBannedPath(real_request_path)) {
            try client_request.respond("", .{ .status = .teapot, .reason = "I'm a teapot!" });
            continue;
        }

        var req_headers = collectRequestHeaders(&client_request);
        const client_content_type = client_request.head.content_type;
        const client_method = client_request.head.method;
        const client_has_expect = client_request.head.expect != null;
        const client_content_length = client_request.head.content_length;
        const client_chunked = client_request.head.transfer_encoding == .chunked;

        const request_path = try std.mem.concat(allocator, u8, &.{ config.upstream, client_request.head.target });
        defer allocator.free(request_path);

        const upstream_uri = std.Uri.parse(request_path) catch {
            try client_request.respond("", .{ .status = .bad_request, .reason = "Bad Request" });
            continue;
        };
        const host_path = if (upstream_uri.host) |host| try allocator.dupe(u8, host.percent_encoded) else "";

        var upstream_request = upstream.request(client_method, upstream_uri, .{
            .headers = .{
                .accept_encoding = .omit,
                .content_type = if (client_content_type) |ct| .{ .override = ct } else .omit,
                .host = .{ .override = host_path },
                .user_agent = .{ .override = req_headers.user_agent orelse DEFAULT_UA },
            },
            .extra_headers = req_headers.items[0..req_headers.len],
            .redirect_behavior = .unhandled,
        }) catch {
            try client_request.respond("", .{ .status = .bad_gateway, .reason = "Bad Gateway" });
            continue;
        };
        defer upstream_request.deinit();

        if (client_method.requestHasBody()) {
            if (client_content_length) |cl| {
                upstream_request.transfer_encoding = .{ .content_length = cl };
            } else if (client_chunked) {
                upstream_request.transfer_encoding = .chunked;
            }
        }

        if (client_has_expect) try client_request.writeExpectContinue();
        const client_request_reader = client_request.readerExpectNone(&upstream_recv_buf);
        if (client_method.requestHasBody()) {
            var upstream_request_body = try upstream_request.sendBody(&upstream_send_buf);
            _ = try client_request_reader.streamRemaining(&upstream_request_body.writer);
            try upstream_request_body.end();
        } else {
            try upstream_request.sendBodiless();
        }

        var redirect_buf: [1024]u8 = undefined;
        var upstream_response = upstream_request.receiveHead(&redirect_buf) catch |err| {
            std.log.err("Upstream response failed: {s}", .{@errorName(err)});
            try client_request.respond("", .{ .status = .bad_gateway, .reason = "Bad Gateway" });
            continue;
        };

        const status = upstream_response.head.status;
        const head_bytes = upstream_response.head.bytes;
        const upstream_cl = upstream_response.head.content_length;
        const upstream_reason = upstream_response.head.reason;
        const upstream_location = upstream_response.head.location;
        const upstream_ct = upstream_response.head.content_type;

        if (status.class() == .informational or status == .no_content or status == .not_modified) {
            upstream_response.request.reader.state = .closing;
            var hdrs: [24]std.http.Header = undefined;
            var scratch: [2048]u8 = undefined;
            const hn = collectResponseHeaders(&hdrs, head_bytes, &scratch);
            var body = try client_request.respondStreaming(&client_body_buf, .{
                .content_length = null,
                .respond_options = .{
                    .status = status,
                    .keep_alive = true,
                    .extra_headers = hdrs[0..hn],
                    .reason = upstream_reason,
                    .transfer_encoding = .none,
                },
            });
            body.end() catch {};
            continue;
        }

        if (status.class() == .redirect) {
            var loc_out: [2048]u8 = undefined;
            var hdrs: [24]std.http.Header = undefined;
            var scratch: [2048]u8 = undefined;
            var hn = collectResponseHeaders(&hdrs, head_bytes, &scratch);
            var resp_status = status;
            var resp_reason: ?[]const u8 = upstream_reason;
            if (upstream_location) |loc| {
                if (mapLocation(&loc_out, loc)) |mapped| {
                    if (hn < hdrs.len) {
                        hdrs[hn] = .{ .name = "location", .value = mapped };
                        hn += 1;
                    }
                } else {
                    std.log.info("Blocked redirect to unmapped location: {s}", .{loc});
                    resp_status = .forbidden;
                    resp_reason = null;
                }
            }
            var body = try client_request.respondStreaming(&client_body_buf, .{
                .content_length = 0,
                .respond_options = .{
                    .status = resp_status,
                    .keep_alive = true,
                    .extra_headers = hdrs[0..hn],
                    .reason = resp_reason,
                },
            });
            body.end() catch {};
            continue;
        }

        const is_head = client_method == .HEAD;
        const rewrite_body = shouldRewriteBody(upstream_ct);
        const do_inject = config.inject_js and isHtmlContentType(upstream_ct);

        var hdrs: [24]std.http.Header = undefined;
        var scratch: [2048]u8 = undefined;
        var hn = collectResponseHeaders(&hdrs, head_bytes, &scratch);

        var cl_buf: [24]u8 = undefined;
        var respond_content_length: ?u64 = null;
        var respond_transfer: ?std.http.TransferEncoding = null;
        if (is_head) {
            respond_transfer = .none;
            if (upstream_cl) |cl| {
                if (std.fmt.bufPrint(&cl_buf, "{d}", .{cl})) |cl_str| {
                    if (hn < hdrs.len) {
                        hdrs[hn] = .{ .name = "content-length", .value = cl_str };
                        hn += 1;
                    }
                } else |_| {}
            }
        } else if (!rewrite_body) {
            respond_content_length = upstream_cl;
        }

        var client_response_body = try client_request.respondStreaming(&client_body_buf, .{
            .content_length = respond_content_length,
            .respond_options = .{
                .status = status,
                .keep_alive = true,
                .extra_headers = hdrs[0..hn],
                .reason = upstream_reason,
                .transfer_encoding = respond_transfer,
            },
        });
        defer client_response_body.end() catch {};

        const upstream_reader: ?*std.Io.Reader = if (is_head) null else upstream_response.reader(&upstream_recv_buf);

        var held: usize = 0;
        var injected: bool = !do_inject or is_head;
        var inject_scan: usize = 0;

        while (true) {
            const n = if (upstream_reader) |ur|
                try ur.readSliceShort(work[held .. held + BODY_CHUNK])
            else
                0;
            const total = held + n;
            const eof = n < BODY_CHUNK;

            var emit_end: usize = total;
            var inj_point: ?usize = null;

            if (!injected) {
                inject_scan += n;
                if (findCi(work[0..total], "<head")) |p| {
                    if (std.mem.indexOfScalar(u8, work[p + 5 .. total], '>')) |g| {
                        inj_point = p + 5 + g + 1;
                    } else {
                        emit_end = p;
                    }
                } else {
                    emit_end = total - headPrefixSuffixLen(work[0..total]);
                }
                if (inj_point == null and (inject_scan > INJECT_SCAN_LIMIT or total - emit_end > HOLD_CAP or eof)) {
                    injected = true;
                    emit_end = total;
                }
            }
            if (!eof) {
                if (urlHoldStart(work[0..emit_end])) |f| emit_end = f;
                if (inj_point) |ip| {
                    if (ip > emit_end) inj_point = null;
                }
            }

            var start: usize = 0;
            if (inj_point) |ip| {
                try emitBody(&client_response_body.writer, work[start..ip], rewrite_body);
                try client_response_body.writer.writeAll("<script>");
                try client_response_body.writer.writeAll(inject_js);
                try client_response_body.writer.writeAll("</script>");
                injected = true;
                start = ip;
            }
            if (start < emit_end) {
                try emitBody(&client_response_body.writer, work[start..emit_end], rewrite_body);
            }

            held = total - emit_end;
            if (held > 0) std.mem.copyForwards(u8, work[0..held], work[emit_end..total]);

            if (eof) {
                if (held > 0) {
                    try emitBody(&client_response_body.writer, work[0..held], rewrite_body);
                    held = 0;
                }
                break;
            }
        }
    }
}

fn handleConnection(init: std.process.Init, sem: *std.Io.Semaphore, client_stream: std.Io.net.Stream) void {
    defer sem.post(init.io);

    var client: std.http.Client = .{
        .allocator = init.gpa,
        .io = init.io,
    };
    defer client.deinit();

    var arena = std.heap.ArenaAllocator.init(init.gpa);
    defer arena.deinit();
    handleClientStream(init.io, arena.allocator(), &client, client_stream) catch |err| switch (err) {
        error.HttpConnectionClosing,
        error.ConnectionReset,
        error.BrokenPipe,
        error.NotConnected,
        error.EndOfStream,
        error.ReadFailed,
        error.WriteFailed,
        => std.log.debug("Connection ended: {s}", .{@errorName(err)}),
        else => std.log.err("ERROR: {s}", .{@errorName(err)}),
    };
}

fn worker(init: std.process.Init, server: *std.Io.net.Server, sem: *std.Io.Semaphore) anyerror!void {
    while (true) {
        sem.waitUncancelable(init.io);
        const client_stream = server.accept(init.io) catch |err| {
            sem.post(init.io);
            std.log.info("Accept client stream failed! {s}", .{@errorName(err)});
            continue;
        };

        std.posix.setsockopt(
            client_stream.socket.handle,
            std.posix.IPPROTO.TCP,
            std.posix.TCP.NODELAY,
            &std.mem.toBytes(@as(c_int, 1)),
        ) catch {};

        const t = std.Thread.spawn(.{
            .allocator = init.gpa,
            .stack_size = 1024 * 1024,
        }, handleConnection, .{ init, sem, client_stream }) catch |err| {
            std.log.err("Spawn connection handler failed: {s}", .{@errorName(err)});
            sem.post(init.io);
            client_stream.close(init.io);
            continue;
        };
        t.detach();
    }
}

pub fn main(init: std.process.Init) !u8 {
    var zon_arena = std.heap.ArenaAllocator.init(init.gpa);
    defer _ = zon_arena.deinit();

    try loadConfig(init.gpa, zon_arena.allocator(), init.io);
    try loadBannedList(init.gpa, zon_arena.allocator(), init.io);

    if (config.inject_js) {
        const inject_js_file = try std.Io.Dir.cwd().openFile(init.io, config.inject_js_path, .{});
        defer inject_js_file.close(init.io);

        const inject_js_file_length = try inject_js_file.length(init.io);
        const inject_js_file_content = try init.gpa.alloc(u8, inject_js_file_length);

        _ = try inject_js_file.readPositionalAll(init.io, inject_js_file_content, 0);
        inject_js = inject_js_file_content;
    }
    defer if (config.inject_js) {
        defer init.gpa.free(inject_js);
    };

    const server_addr = try std.Io.net.IpAddress.parse("127.0.0.1", config.port);
    var server = try server_addr.listen(init.io, .{
        .reuse_address = true,
        .kernel_backlog = 128,
    });
    defer server.deinit(init.io);

    var sem: std.Io.Semaphore = .{ .permits = MAX_CONCURRENT_CONNS };
    try worker(init, &server, &sem);

    return 0;
}
