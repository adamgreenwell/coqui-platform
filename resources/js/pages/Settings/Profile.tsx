import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';

interface PageProps {
  auth: {
    user: {
      id: string;
      name: string;
      email: string;
    };
  };
  [key: string]: unknown;
}

const inputClass =
  'mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition';
const labelClass = 'block text-xs font-medium text-slate-300';
const buttonClass =
  'inline-flex items-center justify-center py-2 px-4 rounded-lg text-sm font-semibold text-slate-950 bg-emerald-500 hover:bg-emerald-400 transition disabled:opacity-50 cursor-pointer';

export default function Profile() {
  const { props } = usePage<PageProps>();
  const user = props.auth.user;

  const profile = useForm({
    name: user.name,
    email: user.email,
  });

  const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
  });

  const submitProfile = (e: FormEvent) => {
    e.preventDefault();
    profile.put('/user/profile-information', {
      errorBag: 'updateProfileInformation',
      preserveScroll: true,
    });
  };

  const submitPassword = (e: FormEvent) => {
    e.preventDefault();
    password.put('/user/password', {
      errorBag: 'updatePassword',
      preserveScroll: true,
      onSuccess: () => password.reset(),
      onError: () => password.reset('password', 'password_confirmation'),
    });
  };

  return (
    <AppLayout>
      <Head title="Profile - Coqui Platform" />
      <div className="max-w-2xl space-y-8">
        <div>
          <h1 className="text-xl font-semibold text-white">Profile &amp; Password</h1>
          <p className="mt-1 text-xs text-slate-400">
            Update your name, sign-in email and password.
          </p>
        </div>

        <section className="bg-slate-900 border border-slate-800 rounded-2xl p-6">
          <h2 className="text-sm font-semibold text-white">Profile information</h2>
          <form onSubmit={submitProfile} className="mt-4 space-y-4">
            <div>
              <label htmlFor="name" className={labelClass}>
                Name
              </label>
              <input
                id="name"
                type="text"
                autoComplete="name"
                required
                value={profile.data.name}
                onChange={(e) => profile.setData('name', e.target.value)}
                className={inputClass}
              />
              {profile.errors.name && (
                <p className="mt-1 text-xs text-red-400">{profile.errors.name}</p>
              )}
            </div>
            <div>
              <label htmlFor="email" className={labelClass}>
                Email address
              </label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                required
                value={profile.data.email}
                onChange={(e) => profile.setData('email', e.target.value)}
                className={inputClass}
              />
              {profile.errors.email && (
                <p className="mt-1 text-xs text-red-400">{profile.errors.email}</p>
              )}
            </div>
            <button type="submit" disabled={profile.processing} className={buttonClass}>
              {profile.processing ? 'Saving...' : 'Save profile'}
            </button>
          </form>
        </section>

        <section className="bg-slate-900 border border-slate-800 rounded-2xl p-6">
          <h2 className="text-sm font-semibold text-white">Change password</h2>
          <form onSubmit={submitPassword} className="mt-4 space-y-4">
            <div>
              <label htmlFor="current_password" className={labelClass}>
                Current password
              </label>
              <input
                id="current_password"
                type="password"
                autoComplete="current-password"
                required
                value={password.data.current_password}
                onChange={(e) => password.setData('current_password', e.target.value)}
                className={inputClass}
              />
              {password.errors.current_password && (
                <p className="mt-1 text-xs text-red-400">{password.errors.current_password}</p>
              )}
            </div>
            <div>
              <label htmlFor="new_password" className={labelClass}>
                New password
              </label>
              <input
                id="new_password"
                type="password"
                autoComplete="new-password"
                required
                value={password.data.password}
                onChange={(e) => password.setData('password', e.target.value)}
                className={inputClass}
              />
              {password.errors.password && (
                <p className="mt-1 text-xs text-red-400">{password.errors.password}</p>
              )}
            </div>
            <div>
              <label htmlFor="new_password_confirmation" className={labelClass}>
                Confirm new password
              </label>
              <input
                id="new_password_confirmation"
                type="password"
                autoComplete="new-password"
                required
                value={password.data.password_confirmation}
                onChange={(e) => password.setData('password_confirmation', e.target.value)}
                className={inputClass}
              />
            </div>
            <button type="submit" disabled={password.processing} className={buttonClass}>
              {password.processing ? 'Saving...' : 'Update password'}
            </button>
          </form>
        </section>
      </div>
    </AppLayout>
  );
}
