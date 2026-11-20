using System.Text.Json.Serialization;

public sealed class AgentConfig {
    [JsonPropertyName("api_base_url")] public string ApiBaseUrl { get; init; } = "";
    [JsonPropertyName("token")] public string Token { get; init; } = "";
}
public sealed class AgentPollResponse {
    [JsonPropertyName("ok")] public bool Ok { get; init; }
    [JsonPropertyName("payment")] public TerminalJob? Payment { get; init; }
}
public sealed class TerminalJob {
    [JsonPropertyName("id")] public string Id { get; init; } = "";
    [JsonPropertyName("amount")] public long Amount { get; init; }
    [JsonPropertyName("terminal_id")] public string TerminalId { get; init; } = "";
    [JsonPropertyName("terminal_name")] public string TerminalName { get; init; } = "";
    [JsonPropertyName("provider_code")] public string ProviderCode { get; init; } = "unknown";
    [JsonPropertyName("model_name")] public string? ModelName { get; init; }
    [JsonPropertyName("driver_code")] public string DriverCode { get; init; } = "unconfigured";
    [JsonPropertyName("connection_type")] public string ConnectionType { get; init; } = "lan";
    [JsonPropertyName("host")] public string Host { get; init; } = "";
    [JsonPropertyName("port")] public int Port { get; init; }
    [JsonPropertyName("local_terminal_key")] public string LocalTerminalKey { get; init; } = "";
}
public sealed class TerminalResult {
    public long? ConfirmedAmount { get; init; }
    public string Status { get; init; } = "unknown";
    public string? ExternalTransactionId { get; init; }
    public string? TraceNumber { get; init; }
    public string? RetrievalReferenceNumber { get; init; }
    public string? ResponseCode { get; init; }
    public string? ResponseMessage { get; init; }
}
public sealed class TerminalResultRequest {
    [JsonPropertyName("status")] public string Status { get; init; } = "unknown";
    [JsonPropertyName("amount")] public long Amount { get; init; }
    [JsonPropertyName("externalTransactionId")] public string? ExternalTransactionId { get; init; }
    [JsonPropertyName("traceNumber")] public string? TraceNumber { get; init; }
    [JsonPropertyName("retrievalReferenceNumber")] public string? RetrievalReferenceNumber { get; init; }
    [JsonPropertyName("responseCode")] public string? ResponseCode { get; init; }
    [JsonPropertyName("responseMessage")] public string? ResponseMessage { get; init; }
    [JsonPropertyName("providerCode")] public string ProviderCode { get; init; } = "unknown";
}
public interface ITerminalDriver {
    string Code { get; }
    Task<TerminalResult> PayAsync(TerminalJob job, CancellationToken cancellationToken);
}
