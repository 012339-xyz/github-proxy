// Copyright 2026 012339-xyz

// Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the “Software”), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

// The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

// THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.


const std = @import("std");

const Config = struct {
    main: []const u8,
    assets: []const u8,
    raw: []const u8,
    objects: []const u8,
    avatars: []const u8,
    gist: []const u8,
    user_images: []const u8,
    upstream: []const u8,
    port: u16,
    banned_zon: []const u8,
};

var config: Config = undefined;
var banned_list: []const []const u8 = undefined;
var banned_mapping: std.StringHashMap(void) = undefined;
var upstream: std.http.Client = undefined;

fn loadConfig(allocator: std.mem.Allocator, io: std.Io) !void {
    const config_file = try std.Io.Dir.cwd().openFile(io, "config.zon", .{});
    defer config_file.close(io);

    const config_file_length = try config_file.length(io);
    const config_file_content = try allocator.alloc(u8, config_file_length + 1);
    defer allocator.free(config_file_content);

    _ = config_file.readPositionalAll(io, config_file_content, 0) catch unreachable;
    config_file_content[config_file_length] = 0;

    config = try std.zon.parse.fromSliceAlloc(Config, allocator, config_file_content[0..config_file_length :0], null, .{});
}

fn loadBannedList(allocator: std.mem.Allocator, io: std.Io) !void {
    const banned_list_file = try std.Io.Dir.cwd().openFile(io, config.banned_zon, .{});
    defer banned_list_file.close(io);

    const banned_list_file_length = try banned_list_file.length(io);
    const banned_list_file_content = try allocator.alloc(u8, banned_list_file_length + 1);
    defer allocator.free(banned_list_file_content);

    _ = banned_list_file.readPositionalAll(io, banned_list_file_content, 0) catch unreachable;
    banned_list_file_content[banned_list_file_length] = 0;

    banned_list = try std.zon.parse.fromSliceAlloc([]const []const u8, allocator, banned_list_file_content[0..banned_list_file_length :0], null, .{});
}

fn writeIfFound(writer: *std.Io.Writer, buf: []const u8, index: usize, found: usize, url: []const u8, mapped: []const u8) !?usize {
    if (std.mem.startsWith(u8, buf[found..], url)) {
        _ = try writer.write(buf[index..found]);
        _ = try writer.write(mapped);
        return found + url.len;
    }
    return null;
}

fn handleClientStream(io: std.Io, _allocator: std.mem.Allocator, client_stream: std.Io.net.Stream) !void {
    defer client_stream.close(io);

    var client_recv_buf: [1024]u8 = undefined;
    var client_send_buf: [1024]u8 = undefined;
    var upstream_recv_buf: [1024]u8 = undefined;
    var upstream_send_buf: [1024]u8 = undefined;
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
                std.log.err("Unsupported request!", .{});
            },
        }

        const real_request_path: []const u8 =
            if (std.mem.cut(u8, client_request.head.target, "?")) |rest|
                rest.@"0"
            else
                client_request.head.target;

        if (banned_mapping.get(real_request_path)) |_| {
            try client_request.respond("", .{ .status = .teapot, .reason = "I'm a teapot!" });
            continue;
        }

        const request_path = try std.mem.concat(allocator, u8, &.{ config.upstream, client_request.head.target });
        defer allocator.free(request_path);

        const upstream_uri = try std.Uri.parse(request_path);
        const host_path = if (upstream_uri.host) |host| try allocator.dupe(u8, host.percent_encoded) else "";
        var upstream_request = upstream.request(client_request.head.method, upstream_uri, .{
            .headers = .{
                .accept_encoding = .{ .override = "" },
                .content_type = .{
                    .override = client_request.head.content_type orelse "",
                },
                .host = .{ .override = host_path },
            },
        }) catch {
            try client_request.respond("", .{ .status = .bad_gateway, .reason = "Bad Gateway" });
            continue;
        };
        defer upstream_request.deinit();

        const client_request_reader = client_request.readerExpectNone(&upstream_recv_buf);
        if (client_request.head.method.requestHasBody()) {
            const upstream_request_body = try upstream_request.sendBody(&upstream_send_buf);
            var upstream_request_body_writer = upstream_request_body.writer;
            _ = try client_request_reader.streamRemaining(&upstream_request_body_writer);
        } else {
            try upstream_request.sendBodiless();
        }

        var redirect_buf: [1024]u8 = undefined;
        var upstream_response = try upstream_request.receiveHead(&redirect_buf);

        var client_response_body = try client_request.respondStreaming(&client_recv_buf, .{
            .content_length = null,
            .respond_options = .{
                .status = upstream_response.head.status,
                .keep_alive = true,
                .extra_headers = &.{
                    .{ .name = "Content-Type", .value = upstream_response.head.content_type orelse "text/plain" },
                },
                .reason = upstream_response.head.reason,
            },
        });
        defer client_response_body.end() catch {};

        const upstream_reader = upstream_response.reader(&upstream_recv_buf);

        while (true) {
            var buf: [1024 * 4]u8 = undefined;
            const upstream_reader_length = try upstream_reader.readSliceShort(&buf);

            var index: usize = 0;
            while (std.mem.findPos(u8, &buf, index, "https://")) |found| {
                if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://github.githubassets.com", config.assets)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://github.com", config.main)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://raw.githubusercontent.com", config.raw)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://avatars.githubusercontent.com", config.avatars)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://gist.github.com", config.gist)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://objects.githubusercontent.com", config.objects)) |new| {
                    index = new;
                    continue;
                } else if (try writeIfFound(&client_response_body.writer, &buf, index, found, "https://user-images.githubusercontent.com", config.user_images)) |new| {
                    index = new;
                    continue;
                } else {
                    // Get here
                    // std.log.err("client_response_body.state = {any}\n", .{client_response_body.state});
                    if (found + 8 < upstream_reader_length) {
                        _ = try client_response_body.writer.write(buf[index .. found + 8]);
                        index = found + 8;
                        continue;
                    } else {
                        _ = try client_response_body.writer.write(buf[index..upstream_reader_length]);
                        break;
                    }
                }
            } else {
                _ = try client_response_body.writer.write(buf[index..upstream_reader_length]);
            }

            if (upstream_reader_length < buf.len) {
                break;
            }
        }
    }
}

fn worker(init: std.process.Init, server: *std.Io.net.Server) anyerror!void {
    while (true) {
        const client_stream = server.accept(init.io) catch |err| {
            std.log.info("Accept client stream failed! {s}", .{@errorName(err)});
            continue;
        };

        var arena = std.heap.ArenaAllocator.init(init.gpa);
        defer arena.deinit();
        // handleClientStream(init.io, arena.allocator(), client_stream) catch |err| {
        // std.log.err("Encounter {s}.\n", .{@errorName(err)});
        // };
        handleClientStream(init.io, arena.allocator(), client_stream) catch |err| {
            std.log.err("ERROR: {s}", .{@errorName(err)});
            continue;
        };
    }
}

pub fn main(init: std.process.Init) !u8 {
    try loadConfig(init.gpa, init.io);
    defer std.zon.parse.free(init.gpa, config);
    try loadBannedList(init.gpa, init.io);
    defer std.zon.parse.free(init.gpa, banned_list);
    banned_mapping = .init(init.gpa);
    defer banned_mapping.deinit();

    upstream = .{
        .allocator = init.gpa,
        .io = init.io,
    };
    defer upstream.deinit();

    for (banned_list) |banned| {
        try banned_mapping.put(banned, {});
    }

    const server_addr = try std.Io.net.IpAddress.parse("127.0.0.1", config.port);
    var server = try server_addr.listen(init.io, .{
        .reuse_address = true,
        .kernel_backlog = 128,
    });
    defer server.deinit(init.io);

    const worker_thread = try std.Thread.spawn(.{
        .allocator = init.gpa,
        .stack_size = 4 * 1024 * 1024,
    }, worker, .{ init, &server });

    worker_thread.detach();

    try worker(init, &server);

    return 0;
}
