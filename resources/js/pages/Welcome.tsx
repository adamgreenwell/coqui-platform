import { Head } from '@inertiajs/react';

interface WelcomeProps {
  appName: string;
  domain: string;
}

export default function Welcome({ appName, domain }: WelcomeProps) {
  return (
    <>
      <Head title="Platform" />
      <main className="mx-auto flex min-h-screen max-w-4xl flex-col justify-center px-6 py-16">
        <span className="mb-3 inline-flex w-fit rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-300">
          {domain}
        </span>
        <h1 className="text-4xl font-semibold tracking-tight text-white">{appName}</h1>
        <p className="mt-4 max-w-2xl text-base text-slate-300">
          Cloud SERP intelligence, DataForSEO rank tracking, backlink monitoring, and hybrid
          Coqui Desktop crawl synchronization built on Laravel 13.
        </p>
      </main>
    </>
  );
}
