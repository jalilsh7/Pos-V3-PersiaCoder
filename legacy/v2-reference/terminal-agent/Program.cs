using System.Net;
using System.Net.Sockets;
using System.Net.Http.Json;
using System.Reflection;
using System.Runtime.Loader;
using System.Text.Json;
using System.Text.RegularExpressions;

const string Version = "0.1.0";
var configPath = args.Length > 0 ? Path.GetFullPath(args[0]) : Path.Combine(AppContext.BaseDirectory, "terminal-agent.json");
if (!File.Exists(configPath)) {
    Console.Error.WriteLine("terminal-agent.json was not found. Copy terminal-agent.example.json and set the token.");
    return 2;
}
AgentConfig? config;
try { config = JsonSerializer.Deserialize<AgentConfig>(await File.ReadAllTextAsync(configPath)); }
catch (Exception ex) { Console.Error.WriteLine("Invalid configuration: " + Safe(ex.Message)); return 2; }
if (config is null || !Uri.TryCreate(config.ApiBaseUrl, UriKind.Absolute, out var apiUri) ||
    apiUri.Scheme != Uri.UriSchemeHttps || !Regex.IsMatch(config.Token, "^[a-f0-9]{64}$")) {
    Console.Error.WriteLine("A valid HTTPS API URL and 64-character token are required.");
    return 2;
}
using var client = new HttpClient { BaseAddress = new Uri(apiUri.ToString().TrimEnd('/') + "/"), Timeout = TimeSpan.FromSeconds(20) };
client.DefaultRequestHeaders.Add("X-Terminal-Agent-Token", config.Token);
client.DefaultRequestHeaders.Add("X-Terminal-Agent-Version", Version);
var drivers = LoadDrivers(Path.Combine(AppContext.BaseDirectory, "drivers"));
Console.WriteLine($"3Food POS terminal agent {Version}; loaded {drivers.Count} driver(s). Press Ctrl+C to stop.");
using var stop = new CancellationTokenSource();
Console.CancelKeyPress += (_, e) => { e.Cancel = true; stop.Cancel(); };
while (!stop.IsCancellationRequested) {
    try {
        using var response = await client.GetAsync("api/v1/terminal-agent/next", stop.Token);
        response.EnsureSuccessStatusCode();
        var poll = await response.Content.ReadFromJsonAsync<AgentPollResponse>(cancellationToken: stop.Token);
        if (poll?.Ok != true) throw new InvalidOperationException("Invalid response from POS API.");
        if (poll.Payment is null) { await Task.Delay(2000, stop.Token); continue; }
        var job = poll.Payment;
        Console.WriteLine($"Payment request {job.Id}; amount {job.Amount}; terminal {job.TerminalName}.");
        TerminalResult result;
        if (!IsPrivateTerminalAddress(job.Host) || job.Port < 1 || job.Port > 65535) {
            result = new TerminalResult { Status = "unknown", ResponseCode = "INVALID_TERMINAL_ADDRESS",
                ResponseMessage = "Terminal address is not a permitted private LAN address; no network command was sent." };
        } else if (!drivers.TryGetValue(job.DriverCode, out var driver)) {
            result = new TerminalResult { Status = "unknown", ResponseCode = "DRIVER_NOT_INSTALLED",
                ResponseMessage = $"No certified driver is installed for '{job.DriverCode}'. No payment command was sent." };
        } else {
            try { result = await driver.PayAsync(job, stop.Token); }
            catch (OperationCanceledException) when (stop.IsCancellationRequested) { throw; }
            catch (Exception ex) {
                result = new TerminalResult { Status = "unknown", ResponseCode = "DRIVER_TRANSPORT_UNKNOWN",
                    ResponseMessage = "Terminal response could not be confirmed: " + Safe(ex.Message) };
            }
        }
        if (!new[] { "approved", "declined", "cancelled", "unknown" }.Contains(result.Status, StringComparer.Ordinal) ||
            (result.Status == "approved" &&
             (result.ConfirmedAmount != job.Amount ||
              (string.IsNullOrWhiteSpace(result.ExternalTransactionId) &&
               string.IsNullOrWhiteSpace(result.TraceNumber) &&
               string.IsNullOrWhiteSpace(result.RetrievalReferenceNumber))))) {
            result = new TerminalResult { Status = "unknown", ResponseCode = "INVALID_DRIVER_RESULT",
                ResponseMessage = "Driver result is incomplete or unsupported; manual reconciliation is required." };
        }
        var payload = new TerminalResultRequest {
            Status = result.Status, Amount = result.Status == "approved" ? result.ConfirmedAmount!.Value : job.Amount,
            ExternalTransactionId = Limit(result.ExternalTransactionId, 120),
            TraceNumber = Limit(result.TraceNumber, 80),
            RetrievalReferenceNumber = Limit(result.RetrievalReferenceNumber, 80),
            ResponseCode = Limit(result.ResponseCode, 40),
            ResponseMessage = Limit(result.ResponseMessage, 240),
            ProviderCode = Limit(job.ProviderCode, 64) ?? "unknown"
        };
        await SubmitResult(client, job.Id, payload, stop.Token);
        Console.WriteLine($"Payment {job.Id} result reported: {result.Status}.");
    } catch (OperationCanceledException) when (stop.IsCancellationRequested) { break; }
      catch (Exception ex) {
        Console.Error.WriteLine("Agent communication error: " + Safe(ex.Message));
        try { await Task.Delay(4000, stop.Token); } catch (OperationCanceledException) { break; }
    }
}
Console.WriteLine("Agent stopped.");
return 0;

static Dictionary<string, ITerminalDriver> LoadDrivers(string directory) {
    var drivers = new Dictionary<string, ITerminalDriver>(StringComparer.OrdinalIgnoreCase);
    if (!Directory.Exists(directory)) return drivers;
    foreach (var file in Directory.EnumerateFiles(directory, "*.dll")) {
        try {
            var assembly = AssemblyLoadContext.Default.LoadFromAssemblyPath(Path.GetFullPath(file));
            foreach (var type in assembly.GetTypes().Where(t => !t.IsAbstract && !t.IsInterface && typeof(ITerminalDriver).IsAssignableFrom(t))) {
                if (Activator.CreateInstance(type) is ITerminalDriver driver && Regex.IsMatch(driver.Code, "^[a-z0-9_-]{1,80}$", RegexOptions.IgnoreCase))
                    drivers[driver.Code] = driver;
            }
        } catch (Exception ex) { Console.Error.WriteLine($"Could not load driver {Path.GetFileName(file)}: {Safe(ex.Message)}"); }
    }
    return drivers;
}
static async Task SubmitResult(HttpClient client, string id, TerminalResultRequest payload, CancellationToken ct) {
    var path = "api/v1/terminal-agent/payments/" + Uri.EscapeDataString(id) + "/result";
    Exception? last = null;
    for (var i = 0; i < 4; i++) {
        try { using var response = await client.PostAsJsonAsync(path, payload, ct); response.EnsureSuccessStatusCode(); return; }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException) {
            last = ex; if (i < 3) await Task.Delay(1000 + i * 1000, ct);
        }
    }
    throw new InvalidOperationException("Could not deliver terminal result to POS API.", last);
}
static string? Limit(string? value, int max) {
    if (string.IsNullOrWhiteSpace(value)) return null;
    var clean = Regex.Replace(value.Trim(), @"[\r\n\t]+", " ");
    return clean.Length <= max ? clean : clean[..max];
}
static string Safe(string value) => Regex.Replace(value, @"[\r\n\t]+", " ");

static bool IsPrivateTerminalAddress(string host) {
    if (!IPAddress.TryParse(host, out var address)) return false;
    if (address.AddressFamily == AddressFamily.InterNetwork) {
        var b = address.GetAddressBytes();
        return b[0] == 10 || (b[0] == 172 && b[1] >= 16 && b[1] <= 31) ||
               (b[0] == 192 && b[1] == 168) || (b[0] == 169 && b[1] == 254);
    }
    return address.AddressFamily == AddressFamily.InterNetworkV6 &&
           (address.IsIPv6UniqueLocal || address.IsIPv6LinkLocal);
}
