# Starting a site with an AI agent

Paste the prompt below into Claude, ChatGPT, OpenCode or any agent that can read
your files. It installs Polaris, asks the questions that matter, and builds the
site under the conventions in `AGENTS.md` instead of inventing its own.

The point is not "generate code faster". It is that the result has **tests,
migrations, security defaults and documentation from the first commit**, because
the framework refuses to make those optional.

---

## The prompt

```
Install Polaris (https://github.com/neo22s/polaris) on my hosting and build a
online fruit shop for me.

Before you write anything, do this:

1. Read AGENTS.md in the repository. It is the contract you must follow: layers,
   conventions, security, i18n, tests and the definition of done.
2. Ask me these questions and wait for the answers. Do not guess:
   - Which hosting do I use, and does it give me FTP, SSH or only a file manager?
   - Do I have a domain, and what is the final URL?
   - Do I have a MySQL database already? Which name and user?
   - Which languages should the shop speak?
   - What does the shop sell: fixed products, weight-based, boxes, subscriptions?
   - How does a customer pay: transfer, cash on delivery, Stripe?
   - Who manages orders: only me, or several people with different permissions?
   - Do I need email to customers (order confirmation) and to me (new order)?
3. Show me a plan: the entities, the screens, the API operations and what you
   will NOT build in the first version. Wait for my approval.
4. Then build it, in this order:
   a. entities with `php bin/make.php crud` (products, orders, order items)
   b. business rules in services, never in controllers or templates
   c. admin screens for the shop owner
   d. the public shop: catalogue, cart, checkout
   e. the API operations, which automatically become MCP tools
   f. tests for the money paths (prices, totals, stock, order state)
   g. translations for every string you wrote
5. Run `composer check` after each block and show me the output. If it fails,
   fix it before moving on. Do not disable a test to make it pass.
6. When it is done, give me: what you built, what is missing, how to deploy with
   `php bin/deploy.php --upload`, and the three things I should check by hand.

Rules you must not break:
- Never edit `kernel/`. Use an extension point or ask me.
- Never commit secrets. `.env` and `.deploy.env` stay out of git.
- Never write an MCP tool by hand: declare an Operation, it becomes REST + MCP.
- Every migration must be idempotent.
- If you are unsure about a requirement, ask. Do not invent a policy about
  payments, taxes or privacy.
```

---

## Why this works better than "make me a shop"

- **It starts with questions.** Most of the failures in AI-built software are
  unwritten requirements: who pays the fees, what happens to stock when two
  people buy the last box, what the invoice looks like.
- **There is a plan gate.** You approve entities and screens before a thousand
  lines exist.
- **`composer check` is the acceptance test.** The agent cannot declare victory
  on code that does not lint or pass tests, because the command decides, not the
  agent.
- **`AGENTS.md` carries the conventions.** Layers, CSRF, prepared statements,
  i18n, migrations and the definition of done are in the repository, not in a
  chat message that scrolls away.
- **The framework makes the good path the easy one.** `bin/make.php` scaffolds
  the correct shape, an API operation produces its MCP tool for free, and the
  kernel already ships sessions, magic links and deployment.

## What the result actually is

A site you own, on your hosting, that:

- has no runtime dependency on a SaaS;
- updates by replacing `kernel/`, not by merging your code with someone else's;
- has an admin, an API, MCP tools and generated docs;
- has tests for the parts that lose money if they break;
- is ordinary PHP you can hand to any developer.

## The honest limits

- **An agent will still get requirements wrong.** The prompt reduces it; it does
  not remove it. Review the plan.
- **Money and privacy need a human.** Taxes, refunds, GDPR, payment flows. An
  agent can implement a decision; it should not make one.
- **It will not replace a designer.** The starter ships plain, readable pages.
  The shop will look like a starter until someone designs it.
- **Shared hosting has limits.** No background workers, no Redis. The kit works
  within that, but a queue-heavy shop needs a different host.
