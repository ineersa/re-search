# Z.AI Platform Capabilities

This document describes the capabilities of all Z.AI models supported by this platform integration.

## Model Capabilities

All Z.AI models support the following capabilities:
- **INPUT_MESSAGES**: Accepts text messages in conversation format
- **INPUT_TEXT**: Accepts plain text input
- **OUTPUT_TEXT**: Generates text responses
- **OUTPUT_STREAMING**: Supports streaming responses via SSE
- **OUTPUT_STRUCTURED**: Supports JSON structured output via `response_format` parameter
- **TOOL_CALLING**: Supports function calling with `tools` and `tool_choice` parameters

**Note**: All Z.AI models are text-only and do not support image, audio, or video modalities natively.

## Model Details

### GLM-5 Series (SOTA)
- **glm-5-turbo**: Optimized for agentic workflows, fast response times
- **glm-5**: State-of-the-art long-horizon execution, strong programming ability

### GLM-4.7 Series (High Performance)
- **glm-4.7**: Enhanced general capabilities, optimized for agentic coding
- **glm-4.7-flash**: Lightweight, high performance (free tier)
- **glm-4.7-flashx**: Ultra-fast variant, enhanced agentic coding

### GLM-4.6 Series
- **glm-4.6**: High performance, strong coding, versatile

### GLM-4.5 Series (Balanced)
- **glm-4.5**: 355B parameters, better performance, strong reasoning
- **glm-4.5-air**: Cost-effective, lightweight, high performance
- **glm-4.5-x**: Good performance, strong reasoning, ultra-fast
- **glm-4.5-airx**: Lightweight, high performance, ultra-fast
- **glm-4.5-flash**: Free tier, strong reasoning, excellent for coding & agents

### Legacy Series
- **glm-4-32b-0414-128k**: 32B parameters, cost-effective foundation model

## Context Windows

- **GLM-5, GLM-4.7, GLM-4.6, GLM-4.5-flash**: 200K tokens
- **GLM-4.5, GLM-4.5-air, GLM-4.5-x, GLM-4.5-airx, GLM-4-32b-0414-128K**: 128K tokens

## Maximum Output Tokens

- **GLM-5, GLM-4.7, GLM-4.6**: 131,072 tokens (default: 65,536)
- **GLM-4.5 series**: 98,304 tokens (default: 65,536)
- **GLM-4-32b-0414-128K**: 16,384 tokens (default: 16,384)

## Special Features

- **Thinking Mode**: Supported by GLM-5, GLM-4.7, GLM-4.6, and GLM-4.5 series
- **Context Caching**: Supported by all models to optimize long conversations
- **Tool Calling**: All models support function calling for agent workflows
- **Streaming**: All models support streaming responses for real-time applications

## Pricing

- **Free Tier Models**: GLM-4.7-flash, GLM-4.5-flash
- **Paid Models**: All other models with per-token pricing

## References

- Z.AI Documentation: https://docs.z.ai/
- API Reference: https://docs.z.ai/api-reference/llm/chat-completion
- Model Catalog: https://docs.z.ai/guides/overview/overview
