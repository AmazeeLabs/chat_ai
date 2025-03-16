
___ATTENTION: This module is in the early alpha release and is not ready for use in PRODUCTION.___

Chat AI adds a chatbot to your website, which can be trained with your website's own data. It is based on the OpenAI API and uses Supabase as a vector database.

First, you have to get an Open AI key and set up a new Supabase project (instructions below). Once you're done with these steps:

1. Go to the module settings page `/admin/config/system/chat-ai/keys` and enter your keys and Rest API URL for your project on Supabase (there is a free tier as well).

2. Go to the Indexing settings page `/admin/config/system/chat-ai/embeddings` and configure which content types you want to include in your index. The chatbot will be able to answer questions based on this context. Instead of selecting fields and labels, the Chat AI module follows a unique approach to indexing: you select your entity type and bundle (e.g. Node, Article) and then select a view mode that you want to index. The module will render the selected view mode and create the associated [embeddings](https://platform.openai.com/docs/guides/embeddings) from the content.

3. On the Indexing settings, after saving your preferences on types, bundles, and view modes, press the `Rebuild tracking information` button to rebuild the indexing information. This action will not create the actual content index, only the information. Don't forget to rebuild your tracking information after updating your `What to index` preferences.

4. If you are happy with your preferences, press the `Queue N items for indexing` button and run the cron to perform the actual indexing.

5. Optionally, navigate to `/admin/config/system/chat-ai/settings` and select your GPT model (default: `gpt-3.5-turbo`). More models are on the way, as well as custom fine-tuned trained models.

___ATTENTION: The use of the Open AI API and Supabase comes with a cost. Always check your costs in the associated platform dashboards.___

6. The actual chat is provided through a block plugin. Go to your website's `/admin/structure/block` block administration page and add the `Chat AI` block. Currently, you can only have one Chat AI block plugin per page.

Currently, the module does not use the previous questions in a chat as context (this feature will be available soon). Moreover, the alpha version does not respect entity permissions yet, so be careful because the content that will be included in your index will be available in the chat. For a full list of upcoming features, check the `Roadmap` section of this document.

## OpenAI API Key

To begin, head to [OpenAI’s](https://platform.openai.com) official platform website. Create an account following the simple steps on the website.

## Supabase setup

Create a new account and a new project on [Supabase](https://supabase.com).

First we'll enable the Vector extension. In Supabase, this can be done from the web portal through Database → Extensions. You can also do this in SQL by running:

```sql
create extension vector;
```

Create a table to store our documents and their embeddings

```sql
create table documents (
  id bigserial primary key,
  langcode text,
  content text,
  embedding vector(1536),
  entity_id int,
  entity_type text,
  bundle text
);
```

Create the similarity search function over these embeddings:

```sql
create or replace function match_documents (
  query_embedding vector(1536),
  match_threshold float,
  match_count int
)
returns table (
  id bigint,
  content text,
  entity_id text,
  entity_type text,
  bundle text,
  langcode text,
  similarity float
)
language sql stable
as $$
  select
    documents.id,
    documents.content,
    documents.entity_id,
    documents.entity_type,
    documents.bundle,
    documents.langcode,
    1 - (documents.embedding <=> query_embedding) as similarity
  from documents
  where 1 - (documents.embedding <=> query_embedding) > match_threshold
  order by similarity desc
  limit match_count;
$$;
```
Once your table starts to grow with embeddings, you will likely want to add an index to speed up queries.

Let's create an index:

```sql
create index on documents using ivfflat (embedding vector_cosine_ops)
with
  (lists = 100);
```

Your Supabase API keys and RESTful endpoint for querying and managing your database can be found under Project Settings in your project dashboard on Supabase. Go to `admin/config/system/chat-ai/settings` and set your credentials.

(Inspiration from this great [article](https://supabase.com/blog/openai-embeddings-postgres-vector).)

## Road map

- Improve error handling.
- Write **tests!**
- Add Pinecone support.
- Move chat history to Supabase and use similarity search for user prompts/questions.
- Embed chat user history as context in the chat completions model.
- Add support for fine-tuning and use of your own models.
- Add custom theme support for the block chat plugin.
- Improve chat UI (e.g., add timestamps).
- Add the ability to use multiple chat blocks with different sources of context (e.g., one chatbot trained with your Q/A, one chatbot trained with help pages, etc.).
- Add chat configuration per block.
- Add token usage statistics (daily/weekly/monthly/all time).
- Ensure chat context respects Drupal entity permissions.

## Misc

* To submit bug reports and feature suggestions, or to track changes visit:
[https://www.drupal.org/project/issues/chat_ai](https://www.drupal.org/project/issues/chat_ai)
