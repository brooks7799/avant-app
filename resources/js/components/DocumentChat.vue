<script setup lang="ts">
import { ref, onMounted, nextTick, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { MessageCircle, Send, Loader2, Trash2, User, Bot, AlertCircle, X, ChevronLeft, ChevronRight, Plus, History, Clock } from 'lucide-vue-next';
import { marked } from 'marked';

interface ChatMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

interface ChatSession {
    id: number;
    title: string;
    message_count: number;
    last_message_at: string | null;
    created_at: string;
}

interface Props {
    documentId: number;
    hasContent: boolean;
}

const props = defineProps<Props>();

const messages = ref<ChatMessage[]>([]);
const inputMessage = ref('');
const isLoading = ref(false);
const isStreaming = ref(false);
const streamingContent = ref('');
const chatContainer = ref<HTMLElement | null>(null);
const errorMessage = ref<string | null>(null);
const isOpen = ref(false);
const isCollapsed = ref(false);
const currentChatId = ref<number | null>(null);
const chatSessions = ref<ChatSession[]>([]);
const showHistory = ref(false);

onMounted(async () => {
    if (props.hasContent) {
        await loadChatHistory();
    }
});

async function loadChatHistory() {
    try {
        const response = await fetch(`/documents/${props.documentId}/chat`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (response.ok) {
            const data = await response.json();
            currentChatId.value = data.chat_id;
            messages.value = data.messages || [];
            chatSessions.value = data.all_chats || [];
            await scrollToBottom();
        }
    } catch (error) {
        console.error('Failed to load chat history:', error);
    }
}

async function loadChatSession(chatId: number) {
    try {
        const response = await fetch(`/documents/${props.documentId}/chat/${chatId}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (response.ok) {
            const data = await response.json();
            currentChatId.value = data.chat_id;
            messages.value = data.messages || [];
            showHistory.value = false;
            await scrollToBottom();
        }
    } catch (error) {
        console.error('Failed to load chat session:', error);
    }
}

async function startNewChat() {
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch(`/documents/${props.documentId}/chat/new`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (response.ok) {
            const data = await response.json();
            currentChatId.value = data.chat_id;
            messages.value = [];
            showHistory.value = false;
            // Refresh chat list
            await loadChatHistory();
        }
    } catch (error) {
        console.error('Failed to start new chat:', error);
    }
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString();
}

async function sendMessage() {
    if (!inputMessage.value.trim() || isLoading.value || isStreaming.value) {
        return;
    }

    const userMessage = inputMessage.value.trim();
    inputMessage.value = '';
    errorMessage.value = null;

    // Add user message immediately
    messages.value.push({
        id: Date.now(),
        role: 'user',
        content: userMessage,
        metadata: null,
        created_at: new Date().toISOString(),
    });

    await scrollToBottom();

    isLoading.value = true;
    isStreaming.value = true;
    streamingContent.value = '';

    // Scroll again after a brief delay to show "Thinking..." indicator
    await nextTick();
    await scrollToBottom();

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch(`/documents/${props.documentId}/chat`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message: userMessage }),
        });

        if (!response.ok) {
            const errorText = await response.text();
            console.error('Chat request failed:', response.status, errorText);
            throw new Error(`Request failed: ${response.status}`);
        }

        const reader = response.body?.getReader();
        const decoder = new TextDecoder();

        if (!reader) {
            throw new Error('No response body');
        }

        isLoading.value = false;
        let buffer = '';

        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                // Process any remaining buffer
                if (streamingContent.value) {
                    messages.value.push({
                        id: Date.now(),
                        role: 'assistant',
                        content: streamingContent.value,
                        metadata: null,
                        created_at: new Date().toISOString(),
                    });
                    streamingContent.value = '';
                }
                isStreaming.value = false;
                await scrollToBottom();
                break;
            }

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || ''; // Keep incomplete line in buffer

            for (const line of lines) {
                const trimmedLine = line.trim();
                if (trimmedLine.startsWith('data: ')) {
                    const data = trimmedLine.slice(6);

                    if (data === '[DONE]') {
                        if (streamingContent.value) {
                            messages.value.push({
                                id: Date.now(),
                                role: 'assistant',
                                content: streamingContent.value,
                                metadata: null,
                                created_at: new Date().toISOString(),
                            });
                            streamingContent.value = '';
                        }
                        isStreaming.value = false;
                        await scrollToBottom();
                        return;
                    }

                    try {
                        const parsed = JSON.parse(data);
                        if (parsed.content) {
                            streamingContent.value += parsed.content;
                            // Auto-scroll as content streams in
                            scrollToBottom();
                        }
                    } catch {
                        // Ignore parse errors for partial chunks
                    }
                }
            }
        }
    } catch (error) {
        console.error('Chat error:', error);
        errorMessage.value = 'Failed to send message. Please try again.';
        isLoading.value = false;
        isStreaming.value = false;
        streamingContent.value = '';
    }
}

async function clearChat() {
    if (!confirm('Clear chat history?')) {
        return;
    }

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const response = await fetch(`/documents/${props.documentId}/chat`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (response.ok) {
            messages.value = [];
        }
    } catch (error) {
        console.error('Failed to clear chat:', error);
    }
}

async function scrollToBottom() {
    await nextTick();
    if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    }
}

function renderMarkdown(content: string): string {
    return marked(content) as string;
}

function handleKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function toggleChat() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        isCollapsed.value = false;
    }
}

function toggleCollapse() {
    isCollapsed.value = !isCollapsed.value;
}
</script>

<template>
    <!-- Floating Chat Button -->
    <div class="fixed bottom-6 right-6 z-50" v-if="!isOpen">
        <Button
            @click="toggleChat"
            size="lg"
            class="rounded-full h-14 w-14 shadow-lg"
            :disabled="!hasContent"
        >
            <MessageCircle class="h-6 w-6" />
        </Button>
    </div>

    <!-- Chat Sidebar -->
    <div
        v-if="isOpen"
        class="fixed top-0 right-0 h-full z-50 flex transition-all duration-300"
        :class="isCollapsed ? 'w-12' : 'w-[55vw] min-w-[500px] max-w-[900px]'"
    >
        <!-- Collapse Toggle -->
        <button
            @click="toggleCollapse"
            class="absolute left-0 top-1/2 -translate-x-full -translate-y-1/2 bg-background border border-r-0 rounded-l-lg p-2 shadow-md hover:bg-muted"
        >
            <ChevronRight v-if="isCollapsed" class="h-4 w-4" />
            <ChevronLeft v-else class="h-4 w-4" />
        </button>

        <!-- Sidebar Content -->
        <div class="flex-1 bg-background border-l shadow-xl flex flex-col h-full">
            <!-- Header -->
            <div class="flex items-center justify-between p-5 border-b bg-muted/30">
                <div v-if="!isCollapsed" class="flex items-center gap-3">
                    <MessageCircle class="h-6 w-6 text-primary" />
                    <span class="font-semibold text-lg">Ask AI</span>
                </div>
                <div v-if="!isCollapsed" class="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="startNewChat"
                        title="New chat"
                    >
                        <Plus class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="showHistory = !showHistory"
                        title="Chat history"
                        :class="showHistory ? 'bg-muted' : ''"
                    >
                        <History class="h-4 w-4" />
                    </Button>
                    <Button
                        v-if="messages.length > 0"
                        variant="ghost"
                        size="sm"
                        @click="clearChat"
                        title="Clear chat"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                    <Button variant="ghost" size="sm" @click="toggleChat" title="Close">
                        <X class="h-4 w-4" />
                    </Button>
                </div>
                <MessageCircle v-if="isCollapsed" class="h-5 w-5 text-primary mx-auto" />
            </div>

            <!-- Content -->
            <div v-if="!isCollapsed" class="flex-1 flex flex-col min-h-0">
                <div v-if="!hasContent" class="flex-1 flex items-center justify-center p-6 text-center text-muted-foreground">
                    <div>
                        <MessageCircle class="h-16 w-16 mx-auto mb-4 opacity-50" />
                        <p class="text-base">Retrieve document content first to enable chat.</p>
                    </div>
                </div>

                <template v-else>
                    <!-- History Panel -->
                    <div v-if="showHistory" class="flex-1 overflow-y-auto p-4 border-b">
                        <div class="space-y-2">
                            <div class="text-sm font-medium text-muted-foreground mb-3 flex items-center gap-2">
                                <History class="h-4 w-4" />
                                Chat History
                            </div>
                            <div v-if="chatSessions.length === 0" class="text-center py-8 text-muted-foreground">
                                <p class="text-sm">No previous chats</p>
                            </div>
                            <button
                                v-for="session in chatSessions"
                                :key="session.id"
                                @click="loadChatSession(session.id)"
                                class="w-full text-left p-3 rounded-lg hover:bg-muted transition-colors"
                                :class="session.id === currentChatId ? 'bg-muted border border-primary/20' : ''"
                            >
                                <div class="font-medium text-sm truncate">{{ session.title }}</div>
                                <div class="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
                                    <Clock class="h-3 w-3" />
                                    {{ formatDate(session.last_message_at) }}
                                    <span class="text-muted-foreground/50">·</span>
                                    {{ session.message_count }} messages
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Chat Messages -->
                    <div
                        v-if="!showHistory"
                        ref="chatContainer"
                        class="flex-1 overflow-y-auto p-8 space-y-8"
                    >
                        <!-- Empty State -->
                        <div v-if="messages.length === 0 && !isStreaming" class="text-center py-16 text-muted-foreground">
                            <Bot class="h-16 w-16 mx-auto mb-6 opacity-50" />
                            <p class="text-xl font-medium">Ask about this document</p>
                            <p class="text-lg mt-4 text-muted-foreground/70">Try: "Can I cancel easily?" or "What data is collected?"</p>
                        </div>

                        <!-- Messages -->
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="flex gap-4"
                            :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                        >
                            <div
                                v-if="message.role === 'assistant'"
                                class="flex-shrink-0 w-11 h-11 rounded-full bg-primary/10 flex items-center justify-center"
                            >
                                <Bot class="h-6 w-6 text-primary" />
                            </div>

                            <div
                                class="max-w-[90%] rounded-2xl p-5"
                                :class="message.role === 'user'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted'"
                            >
                                <div
                                    v-if="message.role === 'assistant'"
                                    class="chat-markdown prose prose-lg dark:prose-invert max-w-none"
                                    v-html="renderMarkdown(message.content)"
                                />
                                <p v-else class="whitespace-pre-wrap text-lg leading-relaxed">{{ message.content }}</p>

                                <Badge
                                    v-if="message.metadata?.is_scope_refusal"
                                    variant="outline"
                                    class="mt-3 text-sm"
                                >
                                    Off topic
                                </Badge>
                            </div>

                            <div
                                v-if="message.role === 'user'"
                                class="flex-shrink-0 w-11 h-11 rounded-full bg-primary flex items-center justify-center"
                            >
                                <User class="h-6 w-6 text-primary-foreground" />
                            </div>
                        </div>

                        <!-- Streaming Response -->
                        <div v-if="isStreaming && streamingContent" class="flex gap-4 justify-start">
                            <div class="flex-shrink-0 w-11 h-11 rounded-full bg-primary/10 flex items-center justify-center">
                                <Bot class="h-6 w-6 text-primary" />
                            </div>
                            <div class="max-w-[90%] rounded-2xl p-5 bg-muted">
                                <div
                                    class="chat-markdown prose prose-lg dark:prose-invert max-w-none"
                                    v-html="renderMarkdown(streamingContent)"
                                />
                                <span class="inline-block w-2 h-6 bg-primary/50 animate-pulse ml-1"></span>
                            </div>
                        </div>

                        <!-- Loading Indicator -->
                        <div v-if="isLoading && !streamingContent" class="flex gap-4 justify-start">
                            <div class="flex-shrink-0 w-11 h-11 rounded-full bg-primary/10 flex items-center justify-center">
                                <Loader2 class="h-6 w-6 text-primary animate-spin" />
                            </div>
                            <div class="bg-muted rounded-2xl p-5">
                                <p class="text-lg text-muted-foreground">Thinking...</p>
                            </div>
                        </div>
                    </div>

                    <!-- Error Message -->
                    <div v-if="errorMessage && !showHistory" class="px-6 pb-3">
                        <div class="flex items-center gap-3 text-destructive text-base bg-destructive/10 rounded-lg p-3">
                            <AlertCircle class="h-5 w-5 flex-shrink-0" />
                            {{ errorMessage }}
                        </div>
                    </div>

                    <!-- Input -->
                    <div v-if="!showHistory" class="p-6 border-t">
                        <div class="flex gap-4">
                            <textarea
                                v-model="inputMessage"
                                placeholder="Ask a question about this document..."
                                class="flex-1 min-h-[56px] max-h-40 resize-none rounded-xl border bg-background px-5 py-4 text-lg ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :disabled="isLoading || isStreaming"
                                @keydown="handleKeydown"
                                rows="1"
                            />
                            <Button
                                @click="sendMessage"
                                :disabled="!inputMessage.trim() || isLoading || isStreaming"
                                size="lg"
                                class="self-end h-14 w-14 p-0 rounded-xl"
                            >
                                <Loader2 v-if="isLoading || isStreaming" class="h-6 w-6 animate-spin" />
                                <Send v-else class="h-6 w-6" />
                            </Button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Professional markdown styling for chat messages */
.chat-markdown {
    font-size: 1.125rem;
    line-height: 1.8;
}

.chat-markdown :deep(p) {
    margin-bottom: 0.75rem;
}

.chat-markdown :deep(p:last-child) {
    margin-bottom: 0;
}

.chat-markdown :deep(ul),
.chat-markdown :deep(ol) {
    margin: 0.75rem 0;
    padding-left: 1.5rem;
}

.chat-markdown :deep(ul) {
    list-style-type: disc;
}

.chat-markdown :deep(ul li::marker) {
    color: currentColor;
}

.chat-markdown :deep(ol li::marker) {
    color: currentColor;
    font-weight: 600;
}

.chat-markdown :deep(li) {
    margin-bottom: 0.5rem;
    padding-left: 0.25rem;
}

.chat-markdown :deep(li:last-child) {
    margin-bottom: 0;
}

.chat-markdown :deep(strong) {
    font-weight: 600;
    color: inherit;
}

.chat-markdown :deep(em) {
    font-style: italic;
}

.chat-markdown :deep(code) {
    background: rgba(0, 0, 0, 0.1);
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    font-size: 0.9em;
    font-family: ui-monospace, monospace;
}

.dark .chat-markdown :deep(code) {
    background: rgba(255, 255, 255, 0.1);
}

.chat-markdown :deep(pre) {
    background: rgba(0, 0, 0, 0.05);
    padding: 0.75rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 0.75rem 0;
}

.dark .chat-markdown :deep(pre) {
    background: rgba(255, 255, 255, 0.05);
}

.chat-markdown :deep(pre code) {
    background: none;
    padding: 0;
}

.chat-markdown :deep(blockquote) {
    border-left: 3px solid currentColor;
    opacity: 0.8;
    padding-left: 1rem;
    margin: 0.75rem 0;
    font-style: italic;
}

.chat-markdown :deep(h1),
.chat-markdown :deep(h2),
.chat-markdown :deep(h3),
.chat-markdown :deep(h4) {
    font-weight: 700;
    margin-top: 1.25rem;
    margin-bottom: 0.625rem;
    color: inherit;
}

.chat-markdown :deep(h1) {
    font-size: 1.5rem;
}

.chat-markdown :deep(h2) {
    font-size: 1.375rem;
}

.chat-markdown :deep(h3) {
    font-size: 1.25rem;
}

.chat-markdown :deep(h4) {
    font-size: 1.125rem;
}

.chat-markdown :deep(a) {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 2px;
}

.chat-markdown :deep(a:hover) {
    opacity: 0.8;
}

.chat-markdown :deep(hr) {
    border: none;
    border-top: 1px solid currentColor;
    opacity: 0.2;
    margin: 1rem 0;
}

.chat-markdown :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0.75rem 0;
    font-size: 0.9em;
}

.chat-markdown :deep(th),
.chat-markdown :deep(td) {
    border: 1px solid currentColor;
    opacity: 0.3;
    padding: 0.5rem;
    text-align: left;
}

.chat-markdown :deep(th) {
    font-weight: 600;
    background: rgba(0, 0, 0, 0.05);
}

.dark .chat-markdown :deep(th) {
    background: rgba(255, 255, 255, 0.05);
}
</style>
