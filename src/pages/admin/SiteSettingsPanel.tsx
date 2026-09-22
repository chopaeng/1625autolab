import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import {
  Settings, Users, MessageSquare, Loader2, AlertCircle,
  Plus, Pencil, Trash2, Save, X, Upload, Star, Layout,
  ServerCog, CheckCircle2, RefreshCw, Database, Info,
  Sparkles, ShieldCheck, ArrowLeft, Play, Copy, Code2,
  Check, FileSpreadsheet,
  ArrowDownToLine, ArrowUpFromLine,
} from 'lucide-react';
import {
  fetchSiteSettingsAsync, updateSiteSettingsAsync,
  fetchTeamMembersAsync, createTeamMemberAsync, updateTeamMemberAsync, deleteTeamMemberAsync,
  fetchTestimonialsAsync, createTestimonialAsync, updateTestimonialAsync, deleteTestimonialAsync,
} from '../../store/siteSettingsSlice';
import {
  uploadAdminImageApi,
  fetchAdminUsersApi,
  fetchAdminRolesApi,
  type AdminManagedUser,
  fetchMigrationStatusApi, runMigrationsApi,
  runNotificationQueueWorkerApi,
  runWaitlistAutoFillWorkerApi,
  runAppointmentRemindersWorkerApi,
  testSheetsWebhookApi,
  syncAllSheetsApi,
  pullSheetsApi,
  getSheetsScriptApi,
  type AdminRole,
  type MigrationEntry,
} from '../../services/api';
import type { AppDispatch, RootState } from '../../store';
import { useAuth } from '../../context/AuthContext';
import type { TeamMember, Testimonial } from '../../types';
import { BACKEND_URL } from '../../config';

const UPLOAD_MAX_MB = 10;
function validateImageFile(file: File): string | null {
  return file.size > UPLOAD_MAX_MB * 1024 * 1024
    ? `Image must be under ${UPLOAD_MAX_MB} MB.`
    : null;
}

// ── Sub-panel: Company Info ───────────────────────────────────────────────────

function CompanyInfoPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { settings, status } = useSelector((s: RootState) => s.siteSettings);

  const [form, setForm] = useState({
    about_heading: '',
    company_description_1: '',
    company_description_2: '',
    company_phones: '',
    company_emails: '',
    about_image_url: '',
    map_embed_url: '',
    map_link_url: '',
  });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [imgUploading, setImgUploading] = useState(false);

  useEffect(() => {
    dispatch(fetchSiteSettingsAsync());
  }, [dispatch]);

  useEffect(() => {
    setForm({
      about_heading: settings.about_heading ?? '',
      company_description_1: settings.company_description_1 ?? '',
      company_description_2: settings.company_description_2 ?? '',
      company_phones: settings.company_phones ?? settings.footer_phones ?? settings.contact_phones ?? '',
      company_emails: settings.company_emails ?? settings.footer_emails ?? settings.contact_emails ?? '',
      about_image_url: settings.about_image_url ?? '',
      map_embed_url: settings.map_embed_url ?? '',
      map_link_url: settings.map_link_url ?? '',
    });
  }, [settings]);

  const parseList = (value: string): string[] => {
    return Array.from(new Set(
      value
        .split(/[\n,]/g)
        .map(item => item.trim())
        .filter(Boolean)
    ));
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setSaving(true);
    setSaveError(null);
    setSaveSuccess(false);
    try {
      const phoneList = parseList(form.company_phones);
      const emailList = parseList(form.company_emails);
      await dispatch(updateSiteSettingsAsync({
        token,
        data: {
          ...form,
          footer_phones: form.company_phones,
          contact_phones: form.company_phones,
          footer_emails: form.company_emails,
          contact_emails: form.company_emails,
          footer_phone: phoneList[0] ?? '',
          contact_phone: phoneList[0] ?? '',
          footer_email: emailList[0] ?? '',
          contact_email: emailList[0] ?? '',
        },
      })).unwrap();
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
    } catch (err: unknown) {
      setSaveError((err as Error)?.message ?? 'Failed to save settings.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 font-sans">
      <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <Settings className="w-5 h-5 text-brand-orange" /> Company &amp; About Overview
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Configure public shop information, about section copy, contact channels, and map embeds.
          </p>
        </div>
      </div>

      {status === 'loading' && (
        <div className="flex justify-center py-12">
          <Loader2 className="w-8 h-8 text-brand-orange animate-spin" />
        </div>
      )}

      {status !== 'loading' && (
        <form onSubmit={handleSave} className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl">
          <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Sparkles className="w-4 h-4" /> About Section &amp; Public Metadata
            </h4>
            <span className="text-[10px] font-mono text-gray-500">Updates live site copy immediately</span>
          </div>

          <div className="p-6 md:p-8 space-y-6">
            {saveError && (
              <div className="flex items-center gap-3 bg-red-950/50 border border-red-500/40 text-red-400 px-5 py-4 rounded-xl text-sm shadow-xl">
                <AlertCircle className="w-5 h-5 shrink-0" /> {saveError}
              </div>
            )}
            {saveSuccess && (
              <div className="flex items-center gap-3 bg-emerald-950/50 border border-emerald-500/40 text-emerald-400 px-5 py-4 rounded-xl text-sm shadow-xl font-mono">
                <CheckCircle2 className="w-5 h-5 shrink-0" /> Company settings saved successfully.
              </div>
            )}

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">About Section Heading</label>
              <input
                value={form.about_heading}
                onChange={e => setForm(f => ({ ...f, about_heading: e.target.value }))}
                placeholder="e.g. Precision Engineering & Automotive Refinement"
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-sans text-sm transition-colors"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Company Description (Paragraph 1)</label>
                <textarea
                  rows={4}
                  value={form.company_description_1}
                  onChange={e => setForm(f => ({ ...f, company_description_1: e.target.value }))}
                  placeholder="Primary shop overview statement..."
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none transition-colors"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Company Description (Paragraph 2)</label>
                <textarea
                  rows={4}
                  value={form.company_description_2}
                  onChange={e => setForm(f => ({ ...f, company_description_2: e.target.value }))}
                  placeholder="Secondary background and philosophy..."
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none transition-colors"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400 flex items-center justify-between">
                  <span>Company Phone Numbers</span>
                  <span className="text-[10px] text-gray-500 font-normal">One per line</span>
                </label>
                <textarea
                  rows={3}
                  value={form.company_phones}
                  onChange={e => setForm(f => ({ ...f, company_phones: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm resize-none transition-colors"
                  placeholder={'09123456789\n+639123456789'}
                />
                <p className="text-[11px] text-gray-500">Single source of truth used across Contact and Footer sections.</p>
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400 flex items-center justify-between">
                  <span>Company Emails</span>
                  <span className="text-[10px] text-gray-500 font-normal">One per line</span>
                </label>
                <textarea
                  rows={3}
                  value={form.company_emails}
                  onChange={e => setForm(f => ({ ...f, company_emails: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm resize-none transition-colors"
                  placeholder={'info@1625autolab.com\nsupport@1625autolab.com'}
                />
                <p className="text-[11px] text-gray-500">Single source of truth used across Contact and Footer sections.</p>
              </div>
            </div>

            <div className="space-y-2 pt-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">About Section Featured Image</label>
              <div className="flex gap-2">
                <input
                  value={form.about_image_url}
                  onChange={e => setForm(f => ({ ...f, about_image_url: e.target.value }))}
                  className="flex-1 bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm font-mono placeholder:text-gray-600 transition-colors"
                  placeholder="https://... or upload local file"
                />
                <label className={`flex items-center gap-2 px-4 py-3 bg-gray-800 border border-gray-700 hover:border-brand-orange text-gray-200 hover:text-white rounded-lg transition-colors cursor-pointer text-xs font-mono font-bold uppercase tracking-wider ${imgUploading ? 'opacity-50 pointer-events-none' : ''}`}>
                  {imgUploading ? <Loader2 className="w-4 h-4 animate-spin text-brand-orange" /> : <Upload className="w-4 h-4 text-brand-orange" />}
                  <span>Upload</span>
                  <input
                    type="file"
                    accept="image/*"
                    className="hidden"
                    disabled={imgUploading}
                    onChange={async e => {
                      const file = e.target.files?.[0];
                      if (!file || !token) return;
                      const sizeErr = validateImageFile(file);
                      if (sizeErr) { setSaveError(sizeErr); e.target.value = ''; return; }
                      setImgUploading(true);
                      try {
                        const url = await uploadAdminImageApi(token, file, 'services');
                        setForm(f => ({ ...f, about_image_url: url }));
                      } catch (err: unknown) {
                        setSaveError((err as Error)?.message ?? 'Image upload failed.');
                      } finally {
                        setImgUploading(false);
                        e.target.value = '';
                      }
                    }}
                  />
                </label>
              </div>
              {form.about_image_url && (
                <div className="relative mt-3 h-36 w-full rounded-lg border border-gray-800 overflow-hidden bg-brand-darker">
                  <img
                    src={form.about_image_url}
                    alt="About section"
                    className="w-full h-full object-cover"
                    referrerPolicy="no-referrer"
                    onError={e => { (e.target as HTMLImageElement).parentElement!.style.display = 'none'; }}
                  />
                  <button
                    type="button"
                    onClick={() => setForm(f => ({ ...f, about_image_url: '' }))}
                    className="absolute top-2 right-2 p-1.5 bg-black/70 hover:bg-red-600 text-white rounded-md transition-colors cursor-pointer"
                    title="Remove image"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>
              )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Map Embed URL (About Page)</label>
                <input
                  value={form.map_embed_url}
                  onChange={e => setForm(f => ({ ...f, map_embed_url: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm placeholder:text-gray-600 transition-colors"
                  placeholder="https://www.openstreetmap.org/export/embed.html?..."
                />
                <p className="text-[11px] text-gray-500">Paste the iframe <code className="bg-brand-darker px-1 rounded text-gray-300 font-mono">src</code> URL from OpenStreetMap or Google Maps.</p>
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Open in Maps External Link</label>
                <input
                  value={form.map_link_url}
                  onChange={e => setForm(f => ({ ...f, map_link_url: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm placeholder:text-gray-600 transition-colors"
                  placeholder="https://maps.google.com/?q=..."
                />
                <p className="text-[11px] text-gray-500">Direct share URL opened when users click "Open in Maps".</p>
              </div>
            </div>
          </div>

          <div className="px-6 py-4 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-end">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-brand-orange text-white px-7 py-2.5 text-xs font-mono font-bold uppercase tracking-widest hover:bg-orange-600 transition-all rounded-lg shadow-lg disabled:opacity-60 cursor-pointer"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              <span>Save Company Info</span>
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

// ── Sub-panel: Team Members ───────────────────────────────────────────────────

type MemberForm = {
  id: number | null;
  userId: number | null;
  name: string; role: string; imageUrl: string;
  bio: string; fullBio: string; email: string; phone: string;
  facebook: string; instagram: string; sortOrder: number; isActive: boolean;
};
const EMPTY_MEMBER: MemberForm = {
  id: null, userId: null, name: '', role: '', imageUrl: '', bio: '', fullBio: '',
  email: '', phone: '', facebook: '', instagram: '', sortOrder: 0, isActive: true,
};

function TeamMembersPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { members, status } = useSelector((s: RootState) => s.siteSettings);

  const [editing, setEditing] = useState(false);
  const [current, setCurrent] = useState<MemberForm>(EMPTY_MEMBER);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [deleteConf, setDeleteConf] = useState<number | null>(null);
  const [imgUploading, setImgUploading] = useState(false);
  const [roleCatalog, setRoleCatalog] = useState<AdminRole[]>([]);
  const [userCatalog, setUserCatalog] = useState<AdminManagedUser[]>([]);
  const [linkedUserSearch, setLinkedUserSearch] = useState('');

  useEffect(() => {
    if (token) dispatch(fetchTeamMembersAsync(token));
  }, [token, dispatch]);

  useEffect(() => {
    if (!token) {
      setRoleCatalog([]);
      return;
    }

    let cancelled = false;
    fetchAdminRolesApi(token)
      .then(({ roles }) => {
        if (!cancelled) setRoleCatalog(roles);
      })
      .catch(() => {
        if (!cancelled) setRoleCatalog([]);
      });

    fetchAdminUsersApi(token)
      .then(({ users }) => {
        if (!cancelled) setUserCatalog(users);
      })
      .catch(() => {
        if (!cancelled) setUserCatalog([]);
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  const roleLabelByKey = useMemo(() => {
    return roleCatalog.reduce<Record<string, string>>((acc, role) => {
      acc[role.key] = role.name;
      return acc;
    }, {});
  }, [roleCatalog]);

  const userLabelById = useMemo(() => {
    return userCatalog.reduce<Record<number, string>>((acc, entry) => {
      acc[entry.id] = `${entry.name} (${entry.email})`;
      return acc;
    }, {});
  }, [userCatalog]);

  const selectableUsers = useMemo(() => {
    const q = linkedUserSearch.trim().toLowerCase();
    return userCatalog
      .filter(entry => entry.role !== 'client')
      .filter(entry => {
        if (q === '') return true;
        return entry.name.toLowerCase().includes(q)
          || entry.email.toLowerCase().includes(q)
          || (entry.phone ?? '').toLowerCase().includes(q);
      });
  }, [userCatalog, linkedUserSearch]);

  const openNew = () => {
    setCurrent(EMPTY_MEMBER);
    setLinkedUserSearch('');
    setSaveError(null);
    setEditing(true);
  };
  const openEdit = (m: TeamMember) => {
    setCurrent({
      id: m.id, userId: m.userId ?? null, name: m.name, role: m.role, imageUrl: m.imageUrl ?? '',
      bio: m.bio ?? '', fullBio: m.fullBio ?? '', email: m.email ?? '',
      phone: m.phone ?? '', facebook: m.facebook ?? '', instagram: m.instagram ?? '',
      sortOrder: m.sortOrder, isActive: m.isActive,
    });
    setSaveError(null);
    setLinkedUserSearch(m.name);
    setEditing(true);
  };
  const cancel = () => {
    setEditing(false);
    setSaveError(null);
    setLinkedUserSearch('');
  };

  const handleSelectUser = (u: AdminManagedUser) => {
    setCurrent(p => ({
      ...p,
      userId: u.id,
      name: p.name || u.name,
      email: p.email || u.email,
      phone: p.phone || (u.phone ?? ''),
      role: p.role || roleLabelByKey[u.role] || u.role,
    }));
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setSaving(true);
    setSaveError(null);

    const payload = {
      userId: current.userId ?? undefined,
      name: current.name,
      role: current.role,
      imageUrl: current.imageUrl,
      bio: current.bio,
      fullBio: current.fullBio,
      email: current.email,
      phone: current.phone,
      facebook: current.facebook,
      instagram: current.instagram,
      sortOrder: current.sortOrder,
      isActive: current.isActive,
    };

    try {
      if (current.id !== null) {
        await dispatch(updateTeamMemberAsync({ token, id: current.id, data: payload })).unwrap();
      } else {
        await dispatch(createTeamMemberAsync({ token, data: payload })).unwrap();
      }
      setEditing(false);
    } catch (err: unknown) {
      setSaveError((err as Error)?.message ?? 'Failed to save team member.');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: number) => {
    if (!token) return;
    await dispatch(deleteTeamMemberAsync({ token, id }));
    setDeleteConf(null);
  };

  if (editing) {
    return (
      <div className="space-y-6 font-sans">
        <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
          <div>
            <button
              onClick={cancel}
              className="inline-flex items-center gap-2 text-xs font-mono uppercase tracking-widest text-gray-400 hover:text-brand-orange transition-colors mb-2 cursor-pointer"
            >
              <ArrowLeft className="w-4 h-4" /> Return to Team Showcase
            </button>
            <h3 className="text-2xl font-display font-black text-white uppercase tracking-tight flex items-center gap-3">
              <Users className="w-6 h-6 text-brand-orange" />
              {current.id ? `Edit Member: ${current.name}` : 'Add New Team Member'}
            </h3>
          </div>
        </div>

        {saveError && (
          <div className="flex items-center gap-3 bg-red-950/50 border border-red-500/40 text-red-400 px-5 py-4 rounded-xl text-sm shadow-xl">
            <AlertCircle className="w-5 h-5 shrink-0" /> {saveError}
          </div>
        )}

        <form onSubmit={handleSave} className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl space-y-6">
          <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Sparkles className="w-4 h-4" /> Staff Profile &amp; Linked System Account
            </h4>
            <span className="text-[10px] font-mono text-gray-500">Public Team Showcase Profile</span>
          </div>

          <div className="p-6 md:p-8 space-y-6">
            <div className="space-y-3 p-4 bg-brand-darker border border-gray-800 rounded-xl">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-300 flex items-center justify-between">
                <span>Link System User Account (Optional)</span>
                {current.userId && (
                  <span className="text-emerald-400 text-[10px] bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded font-mono">
                    Linked to ID #{current.userId}
                  </span>
                )}
              </label>
              <input
                type="text"
                value={linkedUserSearch}
                onChange={e => setLinkedUserSearch(e.target.value)}
                placeholder="Search staff by name or email to auto-fill..."
                className="w-full bg-[#121212] border border-gray-800 text-white px-4 py-2.5 rounded-lg text-xs font-mono focus:outline-none focus:border-brand-orange"
              />
              {selectableUsers.length > 0 && (
                <div className="max-h-36 overflow-y-auto space-y-1 border border-gray-800 rounded-lg p-2 bg-[#121212]">
                  {selectableUsers.map(u => (
                    <button
                      key={u.id}
                      type="button"
                      onClick={() => handleSelectUser(u)}
                      className={`w-full text-left px-3 py-1.5 rounded text-xs font-mono flex items-center justify-between transition-colors ${current.userId === u.id ? 'bg-brand-orange/20 text-brand-orange font-bold' : 'hover:bg-gray-800 text-gray-300'
                        }`}
                    >
                      <span>{u.name} ({u.email})</span>
                      <span className="text-[10px] uppercase text-gray-500">{roleLabelByKey[u.role] || u.role}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Full Name *</label>
                <input
                  required
                  value={current.name}
                  onChange={e => setCurrent(p => ({ ...p, name: e.target.value }))}
                  placeholder="e.g. Master Technician Alex Vance"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Role Title *</label>
                <input
                  required
                  value={current.role}
                  onChange={e => setCurrent(p => ({ ...p, role: e.target.value }))}
                  placeholder="e.g. Head Retrofit Specialist"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Email Address</label>
                <input
                  value={current.email}
                  onChange={e => setCurrent(p => ({ ...p, email: e.target.value }))}
                  placeholder="alex@1625autolab.com"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Phone Contact</label>
                <input
                  value={current.phone}
                  onChange={e => setCurrent(p => ({ ...p, phone: e.target.value }))}
                  placeholder="+63 912 345 6789"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Profile Photo Asset</label>
              <div className="flex gap-2">
                <input
                  value={current.imageUrl}
                  onChange={e => setCurrent(p => ({ ...p, imageUrl: e.target.value }))}
                  className="flex-1 bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm font-mono"
                  placeholder="https://... or upload below"
                />
                <label className={`flex items-center gap-2 px-4 py-3 bg-gray-800 border border-gray-700 hover:border-brand-orange text-gray-200 hover:text-white rounded-lg transition-colors cursor-pointer text-xs font-mono font-bold uppercase tracking-wider ${imgUploading ? 'opacity-50 pointer-events-none' : ''}`}>
                  {imgUploading ? <Loader2 className="w-4 h-4 animate-spin text-brand-orange" /> : <Upload className="w-4 h-4 text-brand-orange" />}
                  <span>Upload</span>
                  <input
                    type="file"
                    accept="image/*"
                    className="hidden"
                    disabled={imgUploading}
                    onChange={async e => {
                      const file = e.target.files?.[0];
                      if (!file || !token) return;
                      const sizeErr = validateImageFile(file);
                      if (sizeErr) { setSaveError(sizeErr); e.target.value = ''; return; }
                      setImgUploading(true);
                      try {
                        const url = await uploadAdminImageApi(token, file, 'team');
                        setCurrent(p => ({ ...p, imageUrl: url }));
                      } catch (err: unknown) {
                        setSaveError((err as Error)?.message ?? 'Image upload failed.');
                      } finally {
                        setImgUploading(false);
                        e.target.value = '';
                      }
                    }}
                  />
                </label>
              </div>

              {current.imageUrl && (
                <div className="relative mt-3 h-28 w-28 rounded-xl border border-gray-800 overflow-hidden bg-brand-darker">
                  <img
                    src={current.imageUrl}
                    alt="Preview"
                    className="w-full h-full object-cover"
                    referrerPolicy="no-referrer"
                    onError={e => { (e.target as HTMLImageElement).parentElement!.style.display = 'none'; }}
                  />
                  <button
                    type="button"
                    onClick={() => setCurrent(p => ({ ...p, imageUrl: '' }))}
                    className="absolute top-1 right-1 p-1 bg-black/70 hover:bg-red-600 text-white rounded-md transition-colors cursor-pointer"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                </div>
              )}
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Short Bio (Team Card)</label>
              <textarea
                rows={2}
                value={current.bio}
                onChange={e => setCurrent(p => ({ ...p, bio: e.target.value }))}
                placeholder="10+ years specializing in Custom Projector Optics & Retrofitting..."
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
              />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Full Profile Biography</label>
              <textarea
                rows={4}
                value={current.fullBio}
                onChange={e => setCurrent(p => ({ ...p, fullBio: e.target.value }))}
                placeholder="Detailed career overview, certifications, and technical specialties..."
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-y"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Instagram Handle</label>
                <input
                  value={current.instagram}
                  onChange={e => setCurrent(p => ({ ...p, instagram: e.target.value }))}
                  placeholder="@handle or full link"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Facebook URL</label>
                <input
                  value={current.facebook}
                  onChange={e => setCurrent(p => ({ ...p, facebook: e.target.value }))}
                  placeholder="https://facebook.com/..."
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Sort Priority</label>
                <input
                  type="number"
                  value={current.sortOrder}
                  onChange={e => setCurrent(p => ({ ...p, sortOrder: Number(e.target.value) }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>
            </div>

            <div className="pt-4 border-t border-gray-800/80 flex items-center justify-between">
              <div>
                <p className="text-xs font-mono font-bold uppercase tracking-wider text-white">Public Visibility</p>
                <p className="text-[11px] text-gray-500">Showcase this team member profile on the public website.</p>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={current.isActive}
                  onChange={e => setCurrent(p => ({ ...p, isActive: e.target.checked }))}
                  className="sr-only peer"
                />
                <div className="w-11 h-6 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                <span className="ml-3 text-xs font-mono font-bold uppercase tracking-wider text-gray-300">
                  {current.isActive ? 'Active (Visible)' : 'Hidden (Draft)'}
                </span>
              </label>
            </div>
          </div>

          <div className="px-6 py-4 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-end gap-3">
            <button
              type="button"
              onClick={cancel}
              className="px-5 py-2.5 border border-gray-800 text-gray-400 hover:text-white text-xs font-mono uppercase tracking-wider rounded-lg transition-colors cursor-pointer"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-brand-orange text-white px-7 py-2.5 text-xs font-mono font-bold uppercase tracking-widest hover:bg-orange-600 transition-all rounded-lg shadow-lg disabled:opacity-60 cursor-pointer"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              <span>{current.id ? 'Save Member Profile' : 'Add Team Member'}</span>
            </button>
          </div>
        </form>
      </div>
    );
  }

  return (
    <div className="space-y-6 font-sans">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <Users className="w-5 h-5 text-brand-orange" /> Team Member Showcase
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Manage staff profiles, technical roles, and system user linkages.
          </p>
        </div>
        <button
          onClick={openNew}
          className="flex items-center gap-2 bg-brand-orange hover:bg-orange-600 text-white px-5 py-2.5 text-xs font-mono font-bold uppercase tracking-widest transition-all rounded-lg shadow-lg cursor-pointer shrink-0"
        >
          <Plus className="w-4 h-4" /> Add Team Member
        </button>
      </div>

      {status === 'loading' && (
        <div className="flex justify-center py-12">
          <Loader2 className="w-8 h-8 text-brand-orange animate-spin" />
        </div>
      )}

      {members.length === 0 && status !== 'loading' && (
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl p-12 text-center shadow-xl space-y-4">
          <Users className="w-12 h-12 text-gray-600 mx-auto opacity-50" />
          <p className="text-xs text-gray-400 font-mono">No team members added yet. Click Add Team Member to create one.</p>
        </div>
      )}

      {members.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {members.map(m => (
            <div
              key={m.id}
              className="bg-[#121212] border border-gray-800/80 hover:border-brand-orange/40 rounded-xl p-5 shadow-xl transition-all flex items-start gap-4 group"
            >
              <div className="w-16 h-16 rounded-xl bg-brand-darker border border-gray-800 overflow-hidden shrink-0">
                {m.imageUrl ? (
                  <img
                    src={m.imageUrl}
                    alt={m.name}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform"
                    referrerPolicy="no-referrer"
                  />
                ) : (
                  <div className="w-full h-full flex items-center justify-center text-gray-600 font-mono font-bold text-lg">
                    {m.name.charAt(0)}
                  </div>
                )}
              </div>

              <div className="flex-1 min-w-0 space-y-1">
                <div className="flex items-center gap-2">
                  <h4 className="text-white font-bold text-base truncate group-hover:text-brand-orange transition-colors">{m.name}</h4>
                  {m.isActive ? (
                    <span className="px-2 py-0.5 text-[9px] font-mono uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 rounded-full font-bold">Active</span>
                  ) : (
                    <span className="px-2 py-0.5 text-[9px] font-mono uppercase bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded-full font-bold">Hidden</span>
                  )}
                </div>
                <p className="text-brand-orange text-xs font-mono font-bold uppercase">{m.role}</p>
                <p className="text-gray-400 text-xs line-clamp-2">{m.bio || 'No bio specified'}</p>

                {m.userId && (
                  <p className="text-[10px] font-mono text-gray-500 pt-1">
                    Linked User: <span className="text-gray-300 font-bold">{userLabelById[m.userId] || `#${m.userId}`}</span>
                  </p>
                )}
              </div>

              <div className="flex items-center gap-1 shrink-0">
                <button
                  onClick={() => openEdit(m)}
                  className="p-2 border border-gray-800 hover:border-brand-orange text-gray-400 hover:text-white rounded-lg transition-colors cursor-pointer"
                  title="Edit Profile"
                >
                  <Pencil className="w-4 h-4" />
                </button>

                {deleteConf === m.id ? (
                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => handleDelete(m.id)}
                      className="px-2.5 py-1.5 bg-red-600 text-white text-[10px] font-mono font-bold uppercase rounded-lg cursor-pointer"
                    >
                      Confirm
                    </button>
                    <button
                      onClick={() => setDeleteConf(null)}
                      className="px-2 py-1.5 border border-gray-700 text-gray-400 hover:text-white text-[10px] font-mono uppercase rounded-lg cursor-pointer"
                    >
                      X
                    </button>
                  </div>
                ) : (
                  <button
                    onClick={() => setDeleteConf(m.id)}
                    className="p-2 border border-gray-800 hover:border-red-500/40 text-gray-500 hover:text-red-400 rounded-lg transition-colors cursor-pointer"
                    title="Delete Member"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Sub-panel: Testimonials ───────────────────────────────────────────────────

type TestimonialForm = {
  id: number | null;
  name: string; role: string; imageUrl: string;
  rating: number; content: string; sortOrder: number;
  isActive: boolean;
};
const EMPTY_TESTIMONIAL: TestimonialForm = {
  id: null, name: '', role: '', imageUrl: '',
  rating: 5, content: '', sortOrder: 0, isActive: true,
};

function TestimonialsPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { testimonials, status } = useSelector((s: RootState) => s.siteSettings);

  const [editing, setEditing] = useState(false);
  const [current, setCurrent] = useState<TestimonialForm>(EMPTY_TESTIMONIAL);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [deleteConf, setDeleteConf] = useState<number | null>(null);
  const [imgUploading, setImgUploading] = useState(false);

  useEffect(() => {
    if (token) dispatch(fetchTestimonialsAsync(token));
  }, [token, dispatch]);

  const openNew = () => { setCurrent(EMPTY_TESTIMONIAL); setSaveError(null); setEditing(true); };
  const openEdit = (t: Testimonial) => {
    setCurrent({
      id: t.id, name: t.name, role: t.role ?? '',
      imageUrl: t.imageUrl ?? '', rating: t.rating, content: t.content,
      sortOrder: t.sortOrder, isActive: t.isActive,
    });
    setSaveError(null);
    setEditing(true);
  };
  const cancel = () => { setEditing(false); setSaveError(null); };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setSaving(true);
    setSaveError(null);

    const payload = {
      name: current.name,
      role: current.role,
      imageUrl: current.imageUrl,
      rating: current.rating,
      content: current.content,
      sortOrder: current.sortOrder,
      isActive: current.isActive,
    };

    try {
      if (current.id !== null) {
        await dispatch(updateTestimonialAsync({ token, id: current.id, data: payload })).unwrap();
      } else {
        await dispatch(createTestimonialAsync({ token, data: payload })).unwrap();
      }
      setEditing(false);
    } catch (err: unknown) {
      setSaveError((err as Error)?.message ?? 'Failed to save testimonial.');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: number) => {
    if (!token) return;
    await dispatch(deleteTestimonialAsync({ token, id }));
    setDeleteConf(null);
  };

  if (editing) {
    return (
      <div className="space-y-6 font-sans">
        <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
          <div>
            <button
              onClick={cancel}
              className="inline-flex items-center gap-2 text-xs font-mono uppercase tracking-widest text-gray-400 hover:text-brand-orange transition-colors mb-2 cursor-pointer"
            >
              <ArrowLeft className="w-4 h-4" /> Return to Testimonials List
            </button>
            <h3 className="text-2xl font-display font-black text-white uppercase tracking-tight flex items-center gap-3">
              <Star className="w-6 h-6 text-brand-orange fill-brand-orange" />
              {current.id ? `Edit Review: ${current.name}` : 'Add New Client Testimonial'}
            </h3>
          </div>
        </div>

        {saveError && (
          <div className="flex items-center gap-3 bg-red-950/50 border border-red-500/40 text-red-400 px-5 py-4 rounded-xl text-sm shadow-xl">
            <AlertCircle className="w-5 h-5 shrink-0" /> {saveError}
          </div>
        )}

        <form onSubmit={handleSave} className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl space-y-6">
          <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Sparkles className="w-4 h-4" /> Client Review &amp; Social Proof
            </h4>
            <span className="text-[10px] font-mono text-gray-500">Public Website Review Card</span>
          </div>

          <div className="p-6 md:p-8 space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Client Name *</label>
                <input
                  required
                  value={current.name}
                  onChange={e => setCurrent(p => ({ ...p, name: e.target.value }))}
                  placeholder="e.g. Marco Rossi"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Vehicle / Subtitle Tag</label>
                <input
                  value={current.role}
                  onChange={e => setCurrent(p => ({ ...p, role: e.target.value }))}
                  placeholder="e.g. Subaru WRX STI Owner"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Rating (1–5 Stars)</label>
                <div className="flex items-center gap-2 pt-1">
                  {[1, 2, 3, 4, 5].map(star => (
                    <button
                      key={star}
                      type="button"
                      onClick={() => setCurrent(p => ({ ...p, rating: star }))}
                      className="p-1 text-amber-400 hover:scale-110 transition-transform cursor-pointer"
                    >
                      <Star className={`w-6 h-6 ${star <= current.rating ? 'fill-amber-400 text-amber-400' : 'text-gray-700'}`} />
                    </button>
                  ))}
                  <span className="text-xs font-mono font-bold text-gray-300 ml-2">{current.rating} / 5 Stars</span>
                </div>
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Avatar Image Asset</label>
                <div className="flex gap-2">
                  <input
                    value={current.imageUrl}
                    onChange={e => setCurrent(p => ({ ...p, imageUrl: e.target.value }))}
                    className="flex-1 bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm font-mono"
                    placeholder="https://... or upload below"
                  />
                  <label className={`flex items-center gap-2 px-4 py-3 bg-gray-800 border border-gray-700 hover:border-brand-orange text-gray-200 hover:text-white rounded-lg transition-colors cursor-pointer text-xs font-mono font-bold uppercase tracking-wider ${imgUploading ? 'opacity-50 pointer-events-none' : ''}`}>
                    {imgUploading ? <Loader2 className="w-4 h-4 animate-spin text-brand-orange" /> : <Upload className="w-4 h-4 text-brand-orange" />}
                    <span>Upload</span>
                    <input
                      type="file"
                      accept="image/*"
                      className="hidden"
                      disabled={imgUploading}
                      onChange={async e => {
                        const file = e.target.files?.[0];
                        if (!file || !token) return;
                        const sizeErr = validateImageFile(file);
                        if (sizeErr) { setSaveError(sizeErr); e.target.value = ''; return; }
                        setImgUploading(true);
                        try {
                          const url = await uploadAdminImageApi(token, file, 'testimonials');
                          setCurrent(p => ({ ...p, imageUrl: url }));
                        } catch (err: unknown) {
                          setSaveError((err as Error)?.message ?? 'Image upload failed.');
                        } finally {
                          setImgUploading(false);
                          e.target.value = '';
                        }
                      }}
                    />
                  </label>
                </div>
              </div>
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Testimonial Review Content *</label>
              <textarea
                required
                rows={4}
                value={current.content}
                onChange={e => setCurrent(p => ({ ...p, content: e.target.value }))}
                placeholder="The beam pattern precision and custom demon eye setup exceeded all expectations..."
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Sort Priority</label>
                <input
                  type="number"
                  value={current.sortOrder}
                  onChange={e => setCurrent(p => ({ ...p, sortOrder: Number(e.target.value) }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                />
              </div>

              <div className="pt-6 flex items-center">
                <label className="flex items-center gap-2 cursor-pointer select-none text-gray-300 text-xs font-mono uppercase font-bold tracking-wider">
                  <input
                    type="checkbox"
                    checked={current.isActive}
                    onChange={e => setCurrent(p => ({ ...p, isActive: e.target.checked }))}
                    className="accent-emerald-500 w-4 h-4 rounded"
                  />
                  Active &amp; Visible on Site
                </label>
              </div>
            </div>
          </div>

          <div className="px-6 py-4 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-end gap-3">
            <button
              type="button"
              onClick={cancel}
              className="px-5 py-2.5 border border-gray-800 text-gray-400 hover:text-white text-xs font-mono uppercase tracking-wider rounded-lg transition-colors cursor-pointer"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-brand-orange text-white px-7 py-2.5 text-xs font-mono font-bold uppercase tracking-widest hover:bg-orange-600 transition-all rounded-lg shadow-lg disabled:opacity-60 cursor-pointer"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              <span>{current.id ? 'Save Changes' : 'Add Testimonial'}</span>
            </button>
          </div>
        </form>
      </div>
    );
  }

  return (
    <div className="space-y-6 font-sans">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <Star className="w-5 h-5 text-amber-400 fill-amber-400" /> Client Testimonials &amp; Reviews
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Manage public client reviews, star ratings, and social proof cards.
          </p>
        </div>
        <button
          onClick={openNew}
          className="flex items-center gap-2 bg-brand-orange hover:bg-orange-600 text-white px-5 py-2.5 text-xs font-mono font-bold uppercase tracking-widest transition-all rounded-lg shadow-lg cursor-pointer shrink-0"
        >
          <Plus className="w-4 h-4" /> Add Testimonial
        </button>
      </div>

      {status === 'loading' && (
        <div className="flex justify-center py-12">
          <Loader2 className="w-8 h-8 text-brand-orange animate-spin" />
        </div>
      )}

      {testimonials.length === 0 && status !== 'loading' && (
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl p-12 text-center shadow-xl space-y-4">
          <Star className="w-12 h-12 text-gray-600 mx-auto opacity-50" />
          <p className="text-xs text-gray-400 font-mono">No testimonials found. Click Add Testimonial to create one.</p>
        </div>
      )}

      {testimonials.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {testimonials.map(t => (
            <div
              key={t.id}
              className="bg-[#121212] border border-gray-800/80 hover:border-brand-orange/40 rounded-xl p-5 shadow-xl transition-all space-y-3 group"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-brand-darker border border-gray-800 overflow-hidden shrink-0">
                    {t.imageUrl ? (
                      <img src={t.imageUrl} alt={t.name} className="w-full h-full object-cover" referrerPolicy="no-referrer" />
                    ) : (
                      <div className="w-full h-full flex items-center justify-center text-gray-500 font-mono font-bold text-xs">
                        {t.name.charAt(0)}
                      </div>
                    )}
                  </div>
                  <div>
                    <h4 className="text-white font-bold text-sm group-hover:text-brand-orange transition-colors">{t.name}</h4>
                    <p className="text-xs text-gray-400 font-mono">{t.role || 'Client'}</p>
                  </div>
                </div>

                <div className="flex items-center gap-1.5 shrink-0">
                  {t.isActive ? (
                    <span className="px-2 py-0.5 text-[9px] font-mono uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 rounded-full font-bold">Active</span>
                  ) : (
                    <span className="px-2 py-0.5 text-[9px] font-mono uppercase bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded-full font-bold">Draft</span>
                  )}
                </div>
              </div>

              <div className="flex items-center gap-1">
                {[1, 2, 3, 4, 5].map(s => (
                  <Star key={s} className={`w-3.5 h-3.5 ${s <= t.rating ? 'fill-amber-400 text-amber-400' : 'text-gray-700'}`} />
                ))}
              </div>

              <p className="text-gray-300 text-xs italic line-clamp-3 leading-relaxed">
                "{t.content}"
              </p>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-gray-800/80">
                <button
                  onClick={() => openEdit(t)}
                  className="p-1.5 border border-gray-800 hover:border-brand-orange text-gray-400 hover:text-white rounded-lg transition-colors cursor-pointer"
                  title="Edit Review"
                >
                  <Pencil className="w-3.5 h-3.5" />
                </button>

                {deleteConf === t.id ? (
                  <div className="flex items-center gap-1">
                    <button
                      onClick={() => handleDelete(t.id)}
                      className="px-2.5 py-1 bg-red-600 text-white text-[10px] font-mono font-bold uppercase rounded-lg cursor-pointer"
                    >
                      Confirm
                    </button>
                    <button
                      onClick={() => setDeleteConf(null)}
                      className="px-2 py-1 border border-gray-700 text-gray-400 hover:text-white text-[10px] font-mono uppercase rounded-lg cursor-pointer"
                    >
                      X
                    </button>
                  </div>
                ) : (
                  <button
                    onClick={() => setDeleteConf(t.id)}
                    className="p-1.5 border border-gray-800 hover:border-red-500/40 text-gray-500 hover:text-red-400 rounded-lg transition-colors cursor-pointer"
                    title="Delete Review"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Sub-panel: Contact Page Settings ─────────────────────────────────────────

function ContactPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { settings, status } = useSelector((s: RootState) => s.siteSettings);

  const [form, setForm] = useState({
    contact_heading: '',
    contact_tagline: '',
    contact_address: '',
    contact_hours: '',
  });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);

  useEffect(() => { dispatch(fetchSiteSettingsAsync()); }, [dispatch]);

  useEffect(() => {
    setForm({
      contact_heading: settings.contact_heading ?? '',
      contact_tagline: settings.contact_tagline ?? '',
      contact_address: settings.contact_address ?? '',
      contact_hours: settings.contact_hours ?? '',
    });
  }, [settings]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setSaving(true); setSaveError(null); setSaveSuccess(false);
    try {
      await dispatch(updateSiteSettingsAsync({ token, data: form })).unwrap();
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
    } catch (err: unknown) {
      setSaveError((err as Error)?.message ?? 'Failed to save settings.');
    } finally { setSaving(false); }
  };

  return (
    <div className="space-y-6 font-sans">
      <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <MessageSquare className="w-5 h-5 text-brand-orange" /> Contact Page Settings
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Configure copy and operational hours displayed on the public /contact page.
          </p>
        </div>
      </div>

      {status === 'loading' && (
        <div className="flex justify-center py-12">
          <Loader2 className="w-8 h-8 text-brand-orange animate-spin" />
        </div>
      )}

      {status !== 'loading' && (
        <form onSubmit={handleSave} className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl">
          <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Sparkles className="w-4 h-4" /> Contact Copy &amp; Business Hours
            </h4>
            <span className="text-[10px] font-mono text-gray-500">Public Contact Section</span>
          </div>

          <div className="p-6 md:p-8 space-y-6">
            {saveError && (
              <div className="flex items-center gap-3 bg-red-950/50 border border-red-500/40 text-red-400 px-5 py-4 rounded-xl text-sm shadow-xl">
                <AlertCircle className="w-5 h-5 shrink-0" /> {saveError}
              </div>
            )}
            {saveSuccess && (
              <div className="flex items-center gap-3 bg-emerald-950/50 border border-emerald-500/40 text-emerald-400 px-5 py-4 rounded-xl text-sm shadow-xl font-mono">
                <CheckCircle2 className="w-5 h-5 shrink-0" /> Contact page settings saved.
              </div>
            )}

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Page Heading</label>
              <input
                value={form.contact_heading}
                onChange={e => setForm(f => ({ ...f, contact_heading: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm"
                placeholder="Contact The Lab"
              />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Tagline / Subheading Intro</label>
              <textarea
                rows={3}
                value={form.contact_tagline}
                onChange={e => setForm(f => ({ ...f, contact_tagline: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
                placeholder="Schedule your retrofit consultation or reach out to our engineering team..."
              />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Shop Physical Address</label>
              <textarea
                rows={2}
                value={form.contact_address}
                onChange={e => setForm(f => ({ ...f, contact_address: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
                placeholder="Full workshop location..."
              />
            </div>

            <div className="p-4 bg-brand-darker border border-gray-800 rounded-xl text-xs font-mono text-gray-400">
              Phone numbers and email addresses are managed in the <span className="text-brand-orange font-bold">Company Info</span> tab.
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400 flex items-center justify-between">
                <span>Business Hours Schedule</span>
                <span className="text-[10px] text-gray-500 font-normal">One schedule entry per line</span>
              </label>
              <textarea
                rows={3}
                value={form.contact_hours}
                onChange={e => setForm(f => ({ ...f, contact_hours: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm resize-none"
                placeholder={'Mon–Fri: 9:00 AM – 6:00 PM\nSat: By Appointment\nSun: Closed'}
              />
            </div>
          </div>

          <div className="px-6 py-4 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-end">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-brand-orange text-white px-7 py-2.5 text-xs font-mono font-bold uppercase tracking-widest hover:bg-orange-600 transition-all rounded-lg shadow-lg disabled:opacity-60 cursor-pointer"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              <span>Save Contact Settings</span>
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

// ── Sub-panel: Footer Settings ────────────────────────────────────────────────

function FooterSettingsPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { settings, status } = useSelector((s: RootState) => s.siteSettings);

  const [form, setForm] = useState({
    footer_tagline: '',
    footer_address: '',
    footer_instagram: '',
    footer_facebook: '',
    footer_youtube: '',
  });
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);

  useEffect(() => { dispatch(fetchSiteSettingsAsync()); }, [dispatch]);

  useEffect(() => {
    setForm({
      footer_tagline: settings.footer_tagline ?? '',
      footer_address: settings.footer_address ?? '',
      footer_instagram: settings.footer_instagram ?? '',
      footer_facebook: settings.footer_facebook ?? '',
      footer_youtube: settings.footer_youtube ?? '',
    });
  }, [settings]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) return;
    setSaving(true); setSaveError(null); setSaveSuccess(false);
    try {
      await dispatch(updateSiteSettingsAsync({ token, data: form })).unwrap();
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
    } catch (err: unknown) {
      setSaveError((err as Error)?.message ?? 'Failed to save settings.');
    } finally { setSaving(false); }
  };

  return (
    <div className="space-y-6 font-sans">
      <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <Layout className="w-5 h-5 text-brand-orange" /> Global Footer Settings
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Configure site-wide footer branding copy, address, and social media channels.
          </p>
        </div>
      </div>

      {status === 'loading' && (
        <div className="flex justify-center py-12">
          <Loader2 className="w-8 h-8 text-brand-orange animate-spin" />
        </div>
      )}

      {status !== 'loading' && (
        <form onSubmit={handleSave} className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl">
          <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Sparkles className="w-4 h-4" /> Global Footer Configuration
            </h4>
            <span className="text-[10px] font-mono text-gray-500">Site-wide Footer Bar</span>
          </div>

          <div className="p-6 md:p-8 space-y-6">
            {saveError && (
              <div className="flex items-center gap-3 bg-red-950/50 border border-red-500/40 text-red-400 px-5 py-4 rounded-xl text-sm shadow-xl">
                <AlertCircle className="w-5 h-5 shrink-0" /> {saveError}
              </div>
            )}
            {saveSuccess && (
              <div className="flex items-center gap-3 bg-emerald-950/50 border border-emerald-500/40 text-emerald-400 px-5 py-4 rounded-xl text-sm shadow-xl font-mono">
                <CheckCircle2 className="w-5 h-5 shrink-0" /> Footer settings saved successfully.
              </div>
            )}

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Footer Tagline</label>
              <textarea
                rows={2}
                value={form.footer_tagline}
                onChange={e => setForm(f => ({ ...f, footer_tagline: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
                placeholder="Short tagline shown under the footer logo..."
              />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Footer Physical Address</label>
              <textarea
                rows={2}
                value={form.footer_address}
                onChange={e => setForm(f => ({ ...f, footer_address: e.target.value }))}
                className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange text-sm resize-none"
                placeholder="Shop address snippet..."
              />
            </div>

            <div className="p-4 bg-brand-darker border border-gray-800 rounded-xl text-xs font-mono text-gray-400">
              Phone numbers and email addresses are managed in the <span className="text-brand-orange font-bold">Company Info</span> tab.
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 pt-2">
              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Instagram URL</label>
                <input
                  value={form.footer_instagram}
                  onChange={e => setForm(f => ({ ...f, footer_instagram: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                  placeholder="https://instagram.com/1625autolab"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">Facebook URL</label>
                <input
                  value={form.footer_facebook}
                  onChange={e => setForm(f => ({ ...f, footer_facebook: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                  placeholder="https://facebook.com/1625autolab"
                />
              </div>

              <div className="space-y-2">
                <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-400">YouTube URL</label>
                <input
                  value={form.footer_youtube}
                  onChange={e => setForm(f => ({ ...f, footer_youtube: e.target.value }))}
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-3 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-sm"
                  placeholder="https://youtube.com/@1625autolab"
                />
              </div>
            </div>
          </div>

          <div className="px-6 py-4 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-end">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-brand-orange text-white px-7 py-2.5 text-xs font-mono font-bold uppercase tracking-widest hover:bg-orange-600 transition-all rounded-lg shadow-lg disabled:opacity-60 cursor-pointer"
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              <span>Save Footer Settings</span>
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

// ── Sub-panel: System Info & Migrations ───────────────────────────────────────

const APP_VERSION = '1.0.0';
const APP_NAME = '1625 Auto Lab';
const TECH_STACK = 'React 19 · Redux Toolkit · Tailwind CSS · PHP 8 · MySQL';

function SystemPanel() {
  const dispatch = useDispatch<AppDispatch>();
  const { token } = useAuth();
  const { settings } = useSelector((s: RootState) => s.siteSettings);

  const [staffBookingSettings, setStaffBookingSettings] = useState({
    staff_can_view_all_bookings: false,
    staff_can_manage_all_bookings: false,
  });
  const [staffSettingsSaving, setStaffSettingsSaving] = useState(false);
  const [staffSettingsError, setStaffSettingsError] = useState<string | null>(null);
  const [staffSettingsSuccess, setStaffSettingsSuccess] = useState(false);

  const [disableRegistration, setDisableRegistration] = useState(false);
  const [registrationSettingsSaving, setRegistrationSettingsSaving] = useState(false);
  const [registrationSettingsError, setRegistrationSettingsError] = useState<string | null>(null);
  const [registrationSettingsSuccess, setRegistrationSettingsSuccess] = useState(false);

  const [shopEnabled, setShopEnabled] = useState(true);
  const [shopSettingsSaving, setShopSettingsSaving] = useState(false);
  const [shopSettingsError, setShopSettingsError] = useState<string | null>(null);
  const [shopSettingsSuccess, setShopSettingsSuccess] = useState(false);

  const [googleSheetsWebhookUrl, setGoogleSheetsWebhookUrl] = useState('');
  const [googleSheetsSyncEnabled, setGoogleSheetsSyncEnabled] = useState(true);
  const [googleSheetsOutboundEnabled, setGoogleSheetsOutboundEnabled] = useState(true);
  const [googleSheetsBidirectionalEnabled, setGoogleSheetsBidirectionalEnabled] = useState(true);

  const [sheetsSettingsSaving, setSheetsSettingsSaving] = useState(false);
  const [sheetsSettingsError, setSheetsSettingsError] = useState<string | null>(null);
  const [sheetsSettingsSuccess, setSheetsSettingsSuccess] = useState<string | null>(null);
  const [testSheetsBusy, setTestSheetsBusy] = useState(false);
  const [syncAllBusy, setSyncAllBusy] = useState(false);
  const [pullSheetsBusy, setPullSheetsBusy] = useState(false);
  const [pullResult, setPullResult] = useState<{ total: number; updated: number; created: number; unchanged: number; errors: string[] } | null>(null);
  const [showScriptModal, setShowScriptModal] = useState(false);
  const [scriptCode, setScriptCode] = useState('');
  const [scriptLoading, setScriptLoading] = useState(false);
  const [copiedInboundUrl, setCopiedInboundUrl] = useState(false);

  const [copiedScript, setCopiedScript] = useState(false);

  const inboundWebhookUrl = typeof window !== 'undefined'
    ? `https://api.1625autolab.com/api/integrations/google-sheets/inbound`
    : '/api/integrations/google-sheets/inbound';

  const [migrations, setMigrations] = useState<MigrationEntry[]>([]);
  const [migrTotal, setMigrTotal] = useState(0);
  const [migrPage, setMigrPage] = useState(1);
  const migrPageSize = 5;
  const [migrCounts, setMigrCounts] = useState<{ ran: number; pending: number; total: number }>({ ran: 0, pending: 0, total: 0 });
  const [migrLoading, setMigrLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [runResult, setRunResult] = useState<{ ran: string[]; skipped: string[]; total: number } | null>(null);
  const [runError, setRunError] = useState<string | null>(null);
  const [cronBusy, setCronBusy] = useState<'queue' | 'waitlist' | 'reminders' | null>(null);
  const [cronResult, setCronResult] = useState<string | null>(null);
  const [cronError, setCronError] = useState<string | null>(null);

  const loadStatus = useCallback(() => {
    if (!token) return;
    setMigrLoading(true);
    fetchMigrationStatusApi(token, { page: migrPage, pageSize: migrPageSize })
      .then(res => {
        setMigrations(res.migrations);
        setMigrTotal(res.total);
        setMigrCounts(res.counts);
      })
      .catch(e => setRunError((e as Error).message))
      .finally(() => setMigrLoading(false));
  }, [token, migrPage, migrPageSize]);

  useEffect(() => { loadStatus(); }, [loadStatus]);

  useEffect(() => {
    dispatch(fetchSiteSettingsAsync());
  }, [dispatch]);

  useEffect(() => {
    const toBool = (value?: string) => ['1', 'true', 'yes', 'on'].includes((value ?? '').toLowerCase());
    setStaffBookingSettings({
      staff_can_view_all_bookings: toBool(settings.staff_can_view_all_bookings),
      staff_can_manage_all_bookings: toBool(settings.staff_can_manage_all_bookings),
    });
    setDisableRegistration(toBool(settings.disable_registration));
    // Default shop_enabled to true (1) if setting is not yet in DB
    setShopEnabled(settings.shop_enabled === undefined ? true : toBool(settings.shop_enabled));
    setGoogleSheetsWebhookUrl(settings.google_sheets_webhook_url ?? '');
    setGoogleSheetsSyncEnabled(settings.google_sheets_sync_enabled === undefined ? true : toBool(settings.google_sheets_sync_enabled));
    setGoogleSheetsOutboundEnabled(settings.google_sheets_outbound_enabled === undefined ? true : toBool(settings.google_sheets_outbound_enabled));
    setGoogleSheetsBidirectionalEnabled(settings.google_sheets_bidirectional_enabled === undefined ? true : toBool(settings.google_sheets_bidirectional_enabled));
  }, [settings.staff_can_manage_all_bookings, settings.staff_can_view_all_bookings, settings.disable_registration, settings.shop_enabled, settings.google_sheets_webhook_url, settings.google_sheets_sync_enabled, settings.google_sheets_outbound_enabled, settings.google_sheets_bidirectional_enabled]);

  const handleSaveRegistrationSettings = async () => {
    if (!token || registrationSettingsSaving) return;
    setRegistrationSettingsSaving(true);
    setRegistrationSettingsError(null);
    setRegistrationSettingsSuccess(false);
    try {
      await dispatch(updateSiteSettingsAsync({
        token,
        data: {
          disable_registration: disableRegistration ? '1' : '0',
        },
      })).unwrap();
      setRegistrationSettingsSuccess(true);
      setTimeout(() => setRegistrationSettingsSuccess(false), 3000);
    } catch (e: unknown) {
      setRegistrationSettingsError((e as Error).message ?? 'Failed to save registration access settings.');
    } finally {
      setRegistrationSettingsSaving(false);
    }
  };

  const handleSaveShopSettings = async () => {
    if (!token || shopSettingsSaving) return;
    setShopSettingsSaving(true);
    setShopSettingsError(null);
    setShopSettingsSuccess(false);
    try {
      await dispatch(updateSiteSettingsAsync({
        token,
        data: { shop_enabled: shopEnabled ? '1' : '0' },
      })).unwrap();
      setShopSettingsSuccess(true);
      setTimeout(() => setShopSettingsSuccess(false), 3000);
    } catch (e: unknown) {
      setShopSettingsError((e as Error).message ?? 'Failed to save shop settings.');
    } finally {
      setShopSettingsSaving(false);
    }
  };



  const copyToClipboard = (text: string, type: 'url' | 'script') => {
    navigator.clipboard.writeText(text);
    if (type === 'url') {
      setCopiedInboundUrl(true);
      setTimeout(() => setCopiedInboundUrl(false), 2000);

    } else {
      setCopiedScript(true);
      setTimeout(() => setCopiedScript(false), 2000);
    }
  };

  const handleSaveSheetsSettings = async () => {
    if (!token || sheetsSettingsSaving) return;
    setSheetsSettingsSaving(true);
    setSheetsSettingsError(null);
    setSheetsSettingsSuccess(null);
    try {
      await dispatch(updateSiteSettingsAsync({
        token,
        data: {
          google_sheets_webhook_url: googleSheetsWebhookUrl.trim(),
          google_sheets_sync_enabled: googleSheetsSyncEnabled ? '1' : '0',
          google_sheets_outbound_enabled: googleSheetsOutboundEnabled ? '1' : '0',
          google_sheets_bidirectional_enabled: googleSheetsBidirectionalEnabled ? '1' : '0',
        },
      })).unwrap();
      setSheetsSettingsSuccess('Google Sheets configuration saved successfully.');
      setTimeout(() => setSheetsSettingsSuccess(null), 4000);
    } catch (e: unknown) {
      setSheetsSettingsError((e as Error).message ?? 'Failed to save Google Sheets settings.');
    } finally {
      setSheetsSettingsSaving(false);
    }
  };

  const handleTestSheetsWebhook = async () => {
    if (!token || testSheetsBusy) return;
    if (!googleSheetsWebhookUrl.trim()) {
      setSheetsSettingsError('Please enter a Webhook URL first.');
      return;
    }
    setTestSheetsBusy(true);
    setSheetsSettingsError(null);
    setSheetsSettingsSuccess(null);
    try {
      const data = await testSheetsWebhookApi(token, googleSheetsWebhookUrl.trim());
      if (data.success === false) {
        throw new Error(data.error || `HTTP ${data.httpCode || 500} — Webhook test failed.`);
      }
      setSheetsSettingsSuccess('Webhook connection verified successfully!');
      setTimeout(() => setSheetsSettingsSuccess(null), 5000);
    } catch (e: unknown) {
      setSheetsSettingsError((e as Error).message ?? 'Webhook test failed.');
    } finally {
      setTestSheetsBusy(false);
    }
  };

  const handleSyncAllSheets = async () => {
    if (!token || syncAllBusy) return;
    setSyncAllBusy(true);
    setSheetsSettingsError(null);
    setSheetsSettingsSuccess(null);
    try {
      const res = await syncAllSheetsApi(token);
      setSheetsSettingsSuccess(`Successfully pushed all inquiries to Google Sheets (${res.syncedCount} records processed)!`);
      setTimeout(() => setSheetsSettingsSuccess(null), 5000);
    } catch (e: unknown) {
      setSheetsSettingsError((e as Error).message ?? 'Sync all failed.');
    } finally {
      setSyncAllBusy(false);
    }
  };

  const handlePullSheets = async () => {
    if (!token || pullSheetsBusy) return;
    setPullSheetsBusy(true);
    setSheetsSettingsError(null);
    setSheetsSettingsSuccess(null);
    setPullResult(null);
    try {
      const res = await pullSheetsApi(token);
      setPullResult({
        total: res.total,
        updated: res.updated,
        created: res.created,
        unchanged: res.unchanged,
        errors: res.errors || [],
      });
      setSheetsSettingsSuccess(`Successfully pulled ${res.total} inquiries from Google Sheets (${res.updated} updated, ${res.created} created, ${res.unchanged} unchanged)!`);
      setTimeout(() => setSheetsSettingsSuccess(null), 8000);
    } catch (e: unknown) {
      setSheetsSettingsError((e as Error).message ?? 'Pull from Google Sheets failed.');
    } finally {
      setPullSheetsBusy(false);
    }
  };

  const handleOpenScriptModal = async () => {
    setShowScriptModal(true);
    if (!scriptCode && token) {
      setScriptLoading(true);
      try {
        const res = await getSheetsScriptApi(token);
        setScriptCode(res.script);
      } catch (e) {
        setSheetsSettingsError((e as Error).message ?? 'Failed to load script template.');
      } finally {
        setScriptLoading(false);
      }
    }
  };

  const handleRunMigrations = async () => {
    if (!token || running) return;
    setRunning(true);
    setRunResult(null);
    setRunError(null);
    try {
      const result = await runMigrationsApi(token);
      setRunResult(result);
      loadStatus();
    } catch (e: unknown) {
      setRunError((e as Error).message ?? 'Failed to run migrations.');
    } finally {
      setRunning(false);
    }
  };

  const pendingCount = migrCounts.pending;
  const ranCount = migrCounts.ran;
  const totalPages = Math.max(1, Math.ceil(migrTotal / migrPageSize));

  const handleRunQueueWorker = async () => {
    if (!token || cronBusy) return;
    setCronBusy('queue');
    setCronResult(null);
    setCronError(null);
    try {
      const res = await runNotificationQueueWorkerApi(token);
      const s = res.stats;
      setCronResult(`Notification queue worker ran: processed ${s.processed}, retried ${s.retried}, failed ${s.failed}.`);
    } catch (e: unknown) {
      setCronError((e as Error).message ?? 'Failed to run notification queue worker.');
    } finally {
      setCronBusy(null);
    }
  };

  const handleRunWaitlistWorker = async () => {
    if (!token || cronBusy) return;
    setCronBusy('waitlist');
    setCronResult(null);
    setCronError(null);
    try {
      const res = await runWaitlistAutoFillWorkerApi(token);
      const s = res.stats;
      setCronResult(`Waitlist auto-fill worker ran: checked ${s.slotsChecked} slot(s), notified ${s.notified} user(s).`);
    } catch (e: unknown) {
      setCronError((e as Error).message ?? 'Failed to run waitlist auto-fill worker.');
    } finally {
      setCronBusy(null);
    }
  };

  const handleRunReminderWorker = async () => {
    if (!token || cronBusy) return;
    setCronBusy('reminders');
    setCronResult(null);
    setCronError(null);
    try {
      const res = await runAppointmentRemindersWorkerApi(token);
      const s = res.stats;
      setCronResult(`Appointment reminders ran for ${s.date}: attempted ${s.attempted}, skipped ${s.skipped}, errors ${s.errors}.`);
    } catch (e: unknown) {
      setCronError((e as Error).message ?? 'Failed to run appointment reminders worker.');
    } finally {
      setCronBusy(null);
    }
  };

  const handleSaveStaffBookingSettings = async () => {
    if (!token || staffSettingsSaving) return;
    setStaffSettingsSaving(true);
    setStaffSettingsError(null);
    setStaffSettingsSuccess(false);
    try {
      await dispatch(updateSiteSettingsAsync({
        token,
        data: {
          staff_can_view_all_bookings: staffBookingSettings.staff_can_view_all_bookings ? '1' : '0',
          staff_can_manage_all_bookings: staffBookingSettings.staff_can_manage_all_bookings ? '1' : '0',
        },
      })).unwrap();
      setStaffSettingsSuccess(true);
      setTimeout(() => setStaffSettingsSuccess(false), 3000);
    } catch (e: unknown) {
      setStaffSettingsError((e as Error).message ?? 'Failed to save staff booking access settings.');
    } finally {
      setStaffSettingsSaving(false);
    }
  };

  return (
    <div className="space-y-8 font-sans">
      <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
        <div>
          <h3 className="text-lg font-display font-black text-white uppercase tracking-tight flex items-center gap-2">
            <ServerCog className="w-5 h-5 text-brand-orange" /> System Operations &amp; Maintenance
          </h3>
          <p className="text-xs text-gray-400 font-mono mt-0.5">
            Monitor environment stats, run database migrations, and execute background cron workers.
          </p>
        </div>
      </div>

      {/* Info Cards Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl p-5 shadow-xl flex items-start gap-4">
          <div className="w-10 h-10 bg-brand-orange/10 border border-brand-orange/20 rounded-lg flex items-center justify-center shrink-0">
            <Info className="w-5 h-5 text-brand-orange" />
          </div>
          <div className="min-w-0">
            <p className="text-[10px] font-mono font-bold uppercase tracking-widest text-gray-400">Application</p>
            <p className="text-white font-mono font-bold text-sm truncate">{APP_NAME}</p>
            <p className="text-gray-500 font-mono text-xs mt-0.5">v{APP_VERSION}</p>
          </div>
        </div>

        <div className="bg-[#121212] border border-gray-800/80 rounded-xl p-5 shadow-xl flex items-start gap-4">
          <div className="w-10 h-10 bg-sky-500/10 border border-sky-500/20 rounded-lg flex items-center justify-center shrink-0">
            <ServerCog className="w-5 h-5 text-sky-400" />
          </div>
          <div className="min-w-0">
            <p className="text-[10px] font-mono font-bold uppercase tracking-widest text-gray-400">Backend API URL</p>
            <p className="text-white font-mono font-bold text-xs truncate">{BACKEND_URL}</p>
            <p className="text-gray-500 font-mono text-xs mt-0.5">PHP Engine</p>
          </div>
        </div>

        <div className="bg-[#121212] border border-gray-800/80 rounded-xl p-5 shadow-xl flex items-start gap-4">
          <div className="w-10 h-10 bg-emerald-500/10 border border-emerald-500/20 rounded-lg flex items-center justify-center shrink-0">
            <Database className="w-5 h-5 text-emerald-400" />
          </div>
          <div className="min-w-0">
            <p className="text-[10px] font-mono font-bold uppercase tracking-widest text-gray-400">Technology Stack</p>
            <p className="text-white font-mono font-bold text-xs truncate">{TECH_STACK}</p>
            <p className="text-emerald-400 font-mono text-xs mt-0.5 font-bold">Operational</p>
          </div>
        </div>
      </div>

      {/* Access Control & Permissions Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Staff Booking Access Permissions */}
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl flex flex-col justify-between">
          <div>
            <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
              <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
                <ShieldCheck className="w-4 h-4" /> Staff Access Policy
              </h4>
              <span className="text-[10px] font-mono text-gray-500">Role Permissions</span>
            </div>

            <div className="p-6 space-y-4">
              {staffSettingsError && (
                <div className="text-xs font-mono text-red-400 bg-red-950/50 p-3 rounded-lg border border-red-500/30">
                  {staffSettingsError}
                </div>
              )}
              {staffSettingsSuccess && (
                <div className="text-xs font-mono text-emerald-400 bg-emerald-950/50 p-3 rounded-lg border border-emerald-500/30">
                  Staff booking permissions updated successfully.
                </div>
              )}

              <div className="flex items-center justify-between p-3 bg-brand-darker border border-gray-800 rounded-lg">
                <div>
                  <p className="text-xs font-mono font-bold uppercase text-white">Staff View All Bookings</p>
                  <p className="text-[11px] text-gray-500">Allows staff to view bookings outside their assigned roster.</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={staffBookingSettings.staff_can_view_all_bookings}
                    onChange={e => setStaffBookingSettings(p => ({ ...p, staff_can_view_all_bookings: e.target.checked }))}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                </label>
              </div>

              <div className="flex items-center justify-between p-3 bg-brand-darker border border-gray-800 rounded-lg">
                <div>
                  <p className="text-xs font-mono font-bold uppercase text-white">Staff Manage All Bookings</p>
                  <p className="text-[11px] text-gray-500">Allows staff to reschedule or modify all appointments.</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={staffBookingSettings.staff_can_manage_all_bookings}
                    onChange={e => setStaffBookingSettings(p => ({ ...p, staff_can_manage_all_bookings: e.target.checked }))}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                </label>
              </div>
            </div>
          </div>

          <div className="px-6 py-3 bg-brand-dark/80 border-t border-gray-800/80 flex justify-end">
            <button
              type="button"
              onClick={handleSaveStaffBookingSettings}
              disabled={staffSettingsSaving}
              className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50"
            >
              {staffSettingsSaving ? 'Saving...' : 'Save Staff Policy'}
            </button>
          </div>
        </div>

        {/* User Registration Disable Switch */}
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl flex flex-col justify-between">
          <div>
            <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
              <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
                <Users className="w-4 h-4" /> Account Registration Access
              </h4>
              <span className="text-[10px] font-mono text-gray-500">Public Portal</span>
            </div>

            <div className="p-6 space-y-4">
              {registrationSettingsError && (
                <div className="text-xs font-mono text-red-400 bg-red-950/50 p-3 rounded-lg border border-red-500/30">
                  {registrationSettingsError}
                </div>
              )}
              {registrationSettingsSuccess && (
                <div className="text-xs font-mono text-emerald-400 bg-emerald-950/50 p-3 rounded-lg border border-emerald-500/30">
                  Registration settings updated.
                </div>
              )}

              <div className="flex items-center justify-between p-4 bg-brand-darker border border-gray-800 rounded-lg">
                <div>
                  <p className="text-xs font-mono font-bold uppercase text-white">Disable New Registration</p>
                  <p className="text-[11px] text-gray-500 mt-0.5">Disables public sign-up form on the website.</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={disableRegistration}
                    onChange={e => setDisableRegistration(e.target.checked)}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                </label>
              </div>
            </div>
          </div>

          <div className="px-6 py-3 bg-brand-dark/80 border-t border-gray-800/80 flex justify-end">
            <button
              type="button"
              onClick={handleSaveRegistrationSettings}
              disabled={registrationSettingsSaving}
              className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50"
            >
              {registrationSettingsSaving ? 'Saving...' : 'Save Registration Policy'}
            </button>
          </div>
        </div>

        {/* Shop / E-Commerce Toggle */}
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl flex flex-col justify-between">
          <div>
            <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between">
              <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
                <Layout className="w-4 h-4" /> Shop &amp; E-Commerce
              </h4>
              <span className="text-[10px] font-mono text-gray-500">Public Storefront</span>
            </div>

            <div className="p-6 space-y-4">
              {shopSettingsError && (
                <div className="text-xs font-mono text-red-400 bg-red-950/50 p-3 rounded-lg border border-red-500/30">
                  {shopSettingsError}
                </div>
              )}
              {shopSettingsSuccess && (
                <div className="text-xs font-mono text-emerald-400 bg-emerald-950/50 p-3 rounded-lg border border-emerald-500/30">
                  Shop settings updated.
                </div>
              )}

              <div className="flex items-center justify-between p-4 bg-brand-darker border border-gray-800 rounded-lg">
                <div>
                  <p className="text-xs font-mono font-bold uppercase text-white">Enable Shop / Products</p>
                  <p className="text-[11px] text-gray-500 mt-0.5">Shows Products nav, Cart, and My Orders across the site. Disable to hide all e-commerce UI.</p>
                </div>
                <label className="relative inline-flex items-center cursor-pointer">
                  <input
                    type="checkbox"
                    checked={shopEnabled}
                    onChange={e => setShopEnabled(e.target.checked)}
                    className="sr-only peer"
                  />
                  <div className="w-11 h-6 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                </label>
              </div>
            </div>
          </div>

          <div className="px-6 py-3 bg-brand-dark/80 border-t border-gray-800/80 flex justify-end">
            <button
              type="button"
              onClick={handleSaveShopSettings}
              disabled={shopSettingsSaving}
              className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50"
            >
              {shopSettingsSaving ? 'Saving...' : 'Save Shop Policy'}
            </button>
          </div>
        </div>

        {/* Google Sheets Live Sync Setting */}
        <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl flex flex-col justify-between">
          <div>
            <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex items-center justify-between flex-wrap gap-2">
              <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
                <Database className="w-4 h-4" /> Google Sheets Live Sync
              </h4>
              <span className={`flex items-center gap-1.5 text-[10px] font-mono font-bold px-2.5 py-0.5 rounded-full border ${googleSheetsSyncEnabled
                  ? 'text-emerald-400 bg-emerald-950/60 border-emerald-500/30'
                  : 'text-gray-400 bg-gray-800/60 border-gray-700'
                }`}>
                <span className={`w-1.5 h-1.5 rounded-full ${googleSheetsSyncEnabled ? 'bg-emerald-400 animate-pulse' : 'bg-gray-500'}`}></span>
                {googleSheetsSyncEnabled ? 'Two-Way Sync Active' : 'Sync Paused'}
              </span>
            </div>

            <div className="p-6 space-y-5">
              {/* Sync Controls / Feature Toggles */}
              <div className="bg-brand-darker/70 border border-gray-800/80 rounded-xl p-4 sm:p-5 space-y-4">
                <div className="flex items-center justify-between gap-3 flex-wrap pb-3 border-b border-gray-800">
                  <div className="flex items-center gap-2">
                    <span className={`w-2.5 h-2.5 rounded-full ${googleSheetsSyncEnabled ? 'bg-emerald-500 animate-pulse' : 'bg-gray-500'}`} />
                    <h4 className="text-xs font-mono font-bold uppercase tracking-wider text-white">Live Sync Controls</h4>
                  </div>
                  <span className={`text-[10px] font-mono font-bold uppercase px-2.5 py-0.5 rounded-full border ${googleSheetsSyncEnabled
                      ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                      : 'bg-gray-800 border-gray-700 text-gray-400'
                    }`}>
                    {googleSheetsSyncEnabled ? '● Integration Active' : '○ Integration Disabled'}
                  </span>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  {/* Master Sync Toggle */}
                  <label className="flex items-start gap-3 p-3 rounded-lg bg-brand-dark border border-gray-800/80 hover:border-gray-700 transition-colors cursor-pointer select-none">
                    <input
                      type="checkbox"
                      checked={googleSheetsSyncEnabled}
                      onChange={e => setGoogleSheetsSyncEnabled(e.target.checked)}
                      className="mt-1 w-4 h-4 rounded border-gray-700 text-brand-orange focus:ring-brand-orange bg-brand-darker accent-brand-orange cursor-pointer"
                    />
                    <div className="space-y-0.5">
                      <div className="text-xs font-bold text-gray-200">Enable Google Sheets Sync</div>
                      <p className="text-[10px] text-gray-400 leading-tight">Master toggle for all inbound and outbound Google Sheets communication.</p>
                    </div>
                  </label>

                  {/* Outbound Sync Toggle */}
                  <label className={`flex items-start gap-3 p-3 rounded-lg bg-brand-dark border transition-colors cursor-pointer select-none ${!googleSheetsSyncEnabled ? 'opacity-50 pointer-events-none border-gray-800' : 'border-gray-800/80 hover:border-gray-700'
                    }`}>
                    <input
                      type="checkbox"
                      disabled={!googleSheetsSyncEnabled}
                      checked={googleSheetsOutboundEnabled}
                      onChange={e => setGoogleSheetsOutboundEnabled(e.target.checked)}
                      className="mt-1 w-4 h-4 rounded border-gray-700 text-brand-orange focus:ring-brand-orange bg-brand-darker accent-brand-orange cursor-pointer"
                    />
                    <div className="space-y-0.5">
                      <div className="text-xs font-bold text-gray-200 flex items-center gap-1">
                        <span>Outbound Push</span>
                        <ArrowUpFromLine className="w-3 h-3 text-brand-orange" />
                      </div>
                      <p className="text-[10px] text-gray-400 leading-tight">Push Apollo customer inquiries and booking status changes to Google Sheets.</p>
                    </div>
                  </label>

                  {/* Bidirectional Inbound Toggle */}
                  <label className={`flex items-start gap-3 p-3 rounded-lg bg-brand-dark border transition-colors cursor-pointer select-none ${!googleSheetsSyncEnabled ? 'opacity-50 pointer-events-none border-gray-800' : 'border-gray-800/80 hover:border-gray-700'
                    }`}>
                    <input
                      type="checkbox"
                      disabled={!googleSheetsSyncEnabled}
                      checked={googleSheetsBidirectionalEnabled}
                      onChange={e => setGoogleSheetsBidirectionalEnabled(e.target.checked)}
                      className="mt-1 w-4 h-4 rounded border-gray-700 text-emerald-400 focus:ring-emerald-400 bg-brand-darker accent-emerald-500 cursor-pointer"
                    />
                    <div className="space-y-0.5">
                      <div className="text-xs font-bold text-gray-200 flex items-center gap-1">
                        <span>Bidirectional Inbound</span>
                        <ArrowDownToLine className="w-3 h-3 text-emerald-400" />
                      </div>
                      <p className="text-[10px] text-gray-400 leading-tight">Allow spreadsheet cell edits in Google Sheets to update Apollo inquiries in real-time.</p>
                    </div>
                  </label>
                </div>

                <div className="flex justify-end pt-1">
                  <button
                    type="button"
                    onClick={handleSaveSheetsSettings}
                    disabled={sheetsSettingsSaving}
                    className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white font-mono text-xs font-bold uppercase tracking-wider rounded-lg transition-colors flex items-center gap-2 cursor-pointer disabled:opacity-50 shadow-md shadow-brand-orange/20"
                  >
                    {sheetsSettingsSaving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                    <span>Save Sync Controls</span>
                  </button>
                </div>
              </div>
              {sheetsSettingsError && (
                <div className="text-xs font-mono text-red-400 bg-red-950/50 p-3 rounded-lg border border-red-500/30">
                  {sheetsSettingsError}
                </div>
              )}
              {sheetsSettingsSuccess && (
                <div className="text-xs font-mono text-emerald-400 bg-emerald-950/50 p-3 rounded-lg border border-emerald-500/30 flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 shrink-0" />
                  <span>{sheetsSettingsSuccess}</span>
                </div>
              )}

              {pullResult && (
                <div className="bg-brand-darker border border-gray-800 p-3.5 rounded-lg space-y-1.5 font-mono text-xs">
                  <div className="text-gray-300 font-bold flex items-center justify-between">
                    <span>Sheet Pull Summary:</span>
                    <span className="text-brand-orange">{pullResult.total} Rows Processed</span>
                  </div>
                  <div className="grid grid-cols-3 gap-2 text-[11px] pt-1">
                    <div className="bg-emerald-950/40 border border-emerald-500/20 text-emerald-300 p-2 rounded text-center">
                      <div className="text-lg font-bold">{pullResult.updated}</div>
                      <div>Updated</div>
                    </div>
                    <div className="bg-blue-950/40 border border-blue-500/20 text-blue-300 p-2 rounded text-center">
                      <div className="text-lg font-bold">{pullResult.created}</div>
                      <div>Created</div>
                    </div>
                    <div className="bg-gray-800/60 border border-gray-700 text-gray-300 p-2 rounded text-center">
                      <div className="text-lg font-bold">{pullResult.unchanged}</div>
                      <div>Unchanged</div>
                    </div>
                  </div>
                  {pullResult.errors.length > 0 && (
                    <div className="text-red-400 text-[11px] pt-1 space-y-0.5">
                      {pullResult.errors.slice(0, 3).map((err, i) => (
                        <div key={i}>⚠️ {err}</div>
                      ))}
                      {pullResult.errors.length > 3 && (
                        <div>...and {pullResult.errors.length - 3} more errors</div>
                      )}
                    </div>
                  )}
                </div>
              )}

              {/* Outbound Webhook URL */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-300 flex items-center gap-1.5">
                    <ArrowUpFromLine className="w-3.5 h-3.5 text-brand-orange" />
                    1. Google Apps Script Web App URL (Outbound)
                  </label>
                  <span className="text-[10px] font-mono text-gray-500">Apollo &rarr; Sheets</span>
                </div>
                <input
                  type="url"
                  value={googleSheetsWebhookUrl}
                  onChange={e => setGoogleSheetsWebhookUrl(e.target.value)}
                  placeholder="https://script.google.com/macros/s/.../exec"
                  className="w-full bg-brand-darker border border-gray-800 text-white px-4 py-2.5 rounded-lg focus:outline-none focus:border-brand-orange font-mono text-xs placeholder:text-gray-600 transition-colors"
                />
                <p className="text-[11px] text-gray-500">
                  Apollo sends customer booking updates to this URL in real-time.
                </p>
              </div>

              {/* Inbound Webhook URL */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-mono font-bold uppercase tracking-widest text-gray-300 flex items-center gap-1.5">
                    <ArrowDownToLine className="w-3.5 h-3.5 text-emerald-400" />
                    2. Apollo Inbound Webhook URL (Inbound)
                  </label>
                  <span className="text-[10px] font-mono text-gray-500">Sheets &rarr; Apollo</span>
                </div>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    readOnly
                    value={inboundWebhookUrl}
                    className="w-full bg-brand-darker border border-gray-800 text-gray-300 px-4 py-2.5 rounded-lg font-mono text-xs select-all focus:outline-none"
                  />
                  <button
                    type="button"
                    onClick={() => copyToClipboard(inboundWebhookUrl, 'url')}
                    className="px-3 py-2.5 bg-gray-800 hover:bg-gray-700 text-gray-200 hover:text-white rounded-lg text-xs font-mono flex items-center gap-1.5 shrink-0 transition-colors border border-gray-700 cursor-pointer"
                    title="Copy Inbound URL"
                  >
                    {copiedInboundUrl ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5 text-gray-400" />}
                    <span>{copiedInboundUrl ? 'Copied!' : 'Copy'}</span>
                  </button>
                </div>
                <p className="text-[11px] text-gray-500">
                  Paste this URL into your Google Apps Script configuration to enable automatic real-time sync when cells are edited in Google Sheets.
                </p>
              </div>



              {settings.google_sheets_last_sync_at && (
                <div className="text-[11px] font-mono text-gray-500 flex items-center gap-1.5">
                  <Info className="w-3.5 h-3.5 text-gray-400" />
                  Last bulk sync performed: {settings.google_sheets_last_sync_at}
                </div>
              )}
            </div>
          </div>

          <div className="px-6 py-3.5 bg-brand-dark/80 border-t border-gray-800/80 flex items-center justify-between gap-3 flex-wrap">
            <button
              type="button"
              onClick={handleOpenScriptModal}
              className="px-3.5 py-2 bg-brand-orange/10 hover:bg-brand-orange/20 text-brand-orange text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer flex items-center gap-2 border border-brand-orange/30"
            >
              <Code2 className="w-3.5 h-3.5" />
              <span>Apps Script (Code.gs)</span>
            </button>

            <div className="flex items-center gap-2.5 flex-wrap">
              <button
                type="button"
                onClick={handlePullSheets}
                disabled={pullSheetsBusy || !googleSheetsWebhookUrl.trim()}
                className="px-3.5 py-2 bg-gray-800 hover:bg-gray-700 text-gray-200 hover:text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50 flex items-center gap-2 border border-gray-700"
                title="Pull and update all rows from Google Sheets into Apollo"
              >
                {pullSheetsBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin text-emerald-400" /> : <ArrowDownToLine className="w-3.5 h-3.5 text-emerald-400" />}
                <span>{pullSheetsBusy ? 'Pulling...' : 'Pull From Sheets'}</span>
              </button>

              <button
                type="button"
                onClick={handleSyncAllSheets}
                disabled={syncAllBusy || !googleSheetsWebhookUrl.trim()}
                className="px-3.5 py-2 bg-gray-800 hover:bg-gray-700 text-gray-200 hover:text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50 flex items-center gap-2 border border-gray-700"
                title="Push all Apollo inquiries to Google Sheets"
              >
                {syncAllBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin text-brand-orange" /> : <RefreshCw className="w-3.5 h-3.5 text-brand-orange" />}
                <span>{syncAllBusy ? 'Pushing...' : 'Push All To Sheets'}</span>
              </button>

              <button
                type="button"
                onClick={handleTestSheetsWebhook}
                disabled={testSheetsBusy || !googleSheetsWebhookUrl.trim()}
                className="px-3.5 py-2 bg-gray-800 hover:bg-gray-700 text-gray-200 hover:text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50 flex items-center gap-2 border border-gray-700"
              >
                {testSheetsBusy ? <Loader2 className="w-3.5 h-3.5 animate-spin text-brand-orange" /> : <Play className="w-3.5 h-3.5 text-brand-orange" />}
                <span>{testSheetsBusy ? 'Testing...' : 'Test'}</span>
              </button>

              <button
                type="button"
                onClick={handleSaveSheetsSettings}
                disabled={sheetsSettingsSaving}
                className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white text-xs font-mono font-bold uppercase tracking-wider rounded-lg transition-all cursor-pointer disabled:opacity-50 flex items-center gap-1.5"
              >
                <Save className="w-3.5 h-3.5" />
                <span>{sheetsSettingsSaving ? 'Saving...' : 'Save Settings'}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Google Apps Script Modal */}
      {showScriptModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#141414] border border-gray-800 rounded-2xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
            {/* Modal Header */}
            <div className="px-6 py-4 border-b border-gray-800 bg-brand-dark/60 flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-brand-orange/10 border border-brand-orange/30 rounded-xl text-brand-orange">
                  <FileSpreadsheet className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-mono font-bold uppercase tracking-wider text-white">Google Apps Script Setup (Code.gs)</h3>
                  <p className="text-xs font-mono text-gray-400">Bidirectional real-time synchronization code for Google Sheets</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setShowScriptModal(false)}
                className="p-1.5 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Content */}
            <div className="p-6 overflow-y-auto space-y-5 text-xs font-mono">
              {/* Instructions */}
              <div className="bg-brand-darker border border-gray-800 rounded-xl p-4 space-y-2.5">
                <h4 className="text-brand-orange font-bold uppercase tracking-wide flex items-center gap-2">
                  <Info className="w-4 h-4" /> 4-Step Setup Guide
                </h4>
                <ol className="list-decimal list-inside space-y-1.5 text-gray-300 text-[11px] leading-relaxed">
                  <li>In your Google Sheet, open <strong className="text-white">Extensions &rarr; Apps Script</strong>.</li>
                  <li>Clear any existing code in the editor, copy the script below, and paste it into <code className="text-brand-orange bg-brand-dark px-1.5 py-0.5 rounded">Code.gs</code>.</li>
                  <li>Click <strong className="text-white">Deploy &rarr; New deployment</strong>, select type <strong className="text-white">Web app</strong>, set <strong className="text-white">"Who has access"</strong> to <strong className="text-white">Anyone</strong>, and click Deploy. Copy the Web App URL into the Outbound URL box in Apollo settings.</li>
                  <li>In your Google Spreadsheet, refresh the page and click the new menu <strong className="text-brand-orange">🏎️ 1625 AutoLab &rarr; 🛠️ Enable Real-Time Auto-Sync (Install Trigger)</strong>.</li>
                </ol>
              </div>

              {/* Code viewer */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-gray-400 font-bold uppercase tracking-wider text-[11px]">Code.gs (Ready with your site configuration)</span>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(scriptCode, 'script')}
                    className="px-3.5 py-1.5 bg-brand-orange hover:bg-orange-600 text-white rounded-lg text-xs font-mono font-bold flex items-center gap-1.5 transition-colors cursor-pointer"
                  >
                    {copiedScript ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                    <span>{copiedScript ? 'Copied Code!' : 'Copy Code.gs'}</span>
                  </button>
                </div>
                {scriptLoading ? (
                  <div className="h-64 flex items-center justify-center bg-black/60 border border-gray-800 rounded-xl text-gray-500 gap-2">
                    <Loader2 className="w-5 h-5 animate-spin text-brand-orange" />
                    <span>Generating customized script...</span>
                  </div>
                ) : (
                  <textarea
                    readOnly
                    value={scriptCode}
                    rows={16}
                    className="w-full bg-black/70 border border-gray-800 text-gray-300 font-mono text-[11px] p-4 rounded-xl leading-relaxed focus:outline-none select-all"
                  />
                )}
              </div>
            </div>

            {/* Modal Footer */}
            <div className="px-6 py-3.5 border-t border-gray-800 bg-brand-dark/40 flex items-center justify-between">
              <span className="text-[11px] text-gray-500 font-mono">Supports all 20 columns &middot; Auto-detects column headers</span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => copyToClipboard(scriptCode, 'script')}
                  className="px-4 py-2 bg-brand-orange hover:bg-orange-600 text-white rounded-lg text-xs font-mono font-bold uppercase tracking-wider cursor-pointer"
                >
                  {copiedScript ? 'Copied to Clipboard!' : 'Copy Script Code'}
                </button>
                <button
                  type="button"
                  onClick={() => setShowScriptModal(false)}
                  className="px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-lg text-xs font-mono cursor-pointer"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Manual Worker Operations Control */}
      <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl p-6 space-y-4">
        <div className="flex items-center justify-between border-b border-gray-800/80 pb-4">
          <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
            <Play className="w-4 h-4" /> Execute Background Cron Workers
          </h4>
          <span className="text-[10px] font-mono text-gray-500">Manual Operations Triggers</span>
        </div>

        {cronResult && (
          <div className="p-3 bg-emerald-950/50 border border-emerald-500/30 text-emerald-400 rounded-lg text-xs font-mono">
            {cronResult}
          </div>
        )}
        {cronError && (
          <div className="p-3 bg-red-950/50 border border-red-500/30 text-red-400 rounded-lg text-xs font-mono">
            {cronError}
          </div>
        )}

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <button
            onClick={handleRunQueueWorker}
            disabled={cronBusy !== null}
            className="flex items-center justify-center gap-2 p-4 bg-brand-darker hover:bg-gray-800 border border-gray-800 hover:border-brand-orange/40 text-gray-200 hover:text-white rounded-xl transition-all cursor-pointer disabled:opacity-50"
          >
            {cronBusy === 'queue' ? <Loader2 className="w-4 h-4 animate-spin text-brand-orange" /> : <RefreshCw className="w-4 h-4 text-brand-orange" />}
            <span className="text-xs font-mono font-bold uppercase">Run Notification Queue</span>
          </button>

          <button
            onClick={handleRunWaitlistWorker}
            disabled={cronBusy !== null}
            className="flex items-center justify-center gap-2 p-4 bg-brand-darker hover:bg-gray-800 border border-gray-800 hover:border-brand-orange/40 text-gray-200 hover:text-white rounded-xl transition-all cursor-pointer disabled:opacity-50"
          >
            {cronBusy === 'waitlist' ? <Loader2 className="w-4 h-4 animate-spin text-sky-400" /> : <RefreshCw className="w-4 h-4 text-sky-400" />}
            <span className="text-xs font-mono font-bold uppercase">Run Waitlist Auto-Fill</span>
          </button>

          <button
            onClick={handleRunReminderWorker}
            disabled={cronBusy !== null}
            className="flex items-center justify-center gap-2 p-4 bg-brand-darker hover:bg-gray-800 border border-gray-800 hover:border-brand-orange/40 text-gray-200 hover:text-white rounded-xl transition-all cursor-pointer disabled:opacity-50"
          >
            {cronBusy === 'reminders' ? <Loader2 className="w-4 h-4 animate-spin text-emerald-400" /> : <RefreshCw className="w-4 h-4 text-emerald-400" />}
            <span className="text-xs font-mono font-bold uppercase">Send Daily Reminders</span>
          </button>
        </div>
      </div>

      {/* Database Migrations Control */}
      <div className="bg-[#121212] border border-gray-800/80 rounded-xl overflow-hidden shadow-2xl space-y-6">
        <div className="px-6 py-4 border-b border-gray-800/80 bg-brand-dark/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h4 className="text-xs font-mono font-bold uppercase tracking-widest text-brand-orange flex items-center gap-2">
              <Database className="w-4 h-4" /> Database Schema Migrations
            </h4>
            <p className="text-[11px] font-mono text-gray-500 mt-0.5">
              Ran: <span className="text-emerald-400 font-bold">{ranCount}</span> · Pending: <span className="text-amber-400 font-bold">{pendingCount}</span> · Total: <span className="text-white font-bold">{migrCounts.total}</span>
            </p>
          </div>

          <button
            onClick={handleRunMigrations}
            disabled={running || pendingCount === 0}
            className="flex items-center gap-2 bg-brand-orange hover:bg-orange-600 text-white px-5 py-2 text-xs font-mono font-bold uppercase tracking-widest rounded-lg transition-all disabled:opacity-50 cursor-pointer shrink-0 shadow-lg"
          >
            {running ? <Loader2 className="w-4 h-4 animate-spin" /> : <Play className="w-4 h-4" />}
            <span>Run Migrations ({pendingCount})</span>
          </button>
        </div>

        <div className="p-6 space-y-4">
          {runResult && (
            <div className="p-4 bg-emerald-950/50 border border-emerald-500/30 text-emerald-400 rounded-xl text-xs font-mono space-y-1">
              <p className="font-bold">Migrations executed successfully! Ran {runResult.ran.length} file(s).</p>
              {runResult.ran.map(f => <p key={f}>✓ {f}</p>)}
            </div>
          )}

          {runError && (
            <div className="p-4 bg-red-950/50 border border-red-500/30 text-red-400 rounded-xl text-xs font-mono">
              {runError}
            </div>
          )}

          {migrLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="w-6 h-6 text-brand-orange animate-spin" />
            </div>
          ) : (
            <div className="border border-gray-800 rounded-xl overflow-x-auto bg-brand-darker">
              <table className="w-full text-left text-xs font-mono">
                <thead>
                  <tr className="border-b border-gray-800 bg-black/40 text-gray-400 uppercase text-[10px] tracking-wider">
                    <th className="py-3 px-4">Migration File</th>
                    <th className="py-3 px-4">Status</th>
                    <th className="py-3 px-4">Execution Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-800/60 text-gray-300">
                  {migrations.map(m => (
                    <tr key={m.name} className="hover:bg-gray-800/30 transition-colors">
                      <td className="py-3 px-4 font-semibold text-white">{m.name}</td>
                      <td className="py-3 px-4">
                        {m.status === 'ran' ? (
                          <span className="px-2 py-0.5 text-[9px] uppercase font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 rounded-full">
                            RAN
                          </span>
                        ) : (
                          <span className="px-2 py-0.5 text-[9px] uppercase font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30 rounded-full">
                            PENDING
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-gray-400">{m.ran_at ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* Pagination Footer */}
              <div className="px-4 py-3 border-t border-gray-800 flex items-center justify-between bg-black/40">
                <span className="text-[11px] text-gray-500">
                  Page {migrPage} of {totalPages} ({migrTotal} entries)
                </span>
                <div className="flex items-center gap-2">
                  <button
                    disabled={migrPage <= 1}
                    onClick={() => setMigrPage(p => p - 1)}
                    className="px-3 py-1 border border-gray-800 text-gray-400 hover:text-white disabled:opacity-40 rounded text-xs cursor-pointer"
                  >
                    Previous
                  </button>
                  <button
                    disabled={migrPage >= totalPages}
                    onClick={() => setMigrPage(p => p + 1)}
                    className="px-3 py-1 border border-gray-800 text-gray-400 hover:text-white disabled:opacity-40 rounded text-xs cursor-pointer"
                  >
                    Next
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ── Main SiteSettingsPanel ────────────────────────────────────────────────────

type Tab = 'company' | 'contact' | 'footer' | 'team' | 'testimonials' | 'system';

export default function SiteSettingsPanel() {
  const [activeTab, setActiveTab] = useState<Tab>('company');

  const tabs: { key: Tab; label: string; hint: string; icon: React.ReactNode }[] = useMemo(() => [
    {
      key: 'company',
      label: 'Company Info',
      hint: 'About section and map details',
      icon: <Settings className="w-4 h-4" />,
    },
    {
      key: 'contact',
      label: 'Contact Page',
      hint: 'Contact copy and support channels',
      icon: <MessageSquare className="w-4 h-4" />,
    },
    {
      key: 'footer',
      label: 'Footer Settings',
      hint: 'Global footer links and legal text',
      icon: <Layout className="w-4 h-4" />,
    },
    {
      key: 'team',
      label: 'Team Members',
      hint: 'Staff profiles and showcase ordering',
      icon: <Users className="w-4 h-4" />,
    },
    {
      key: 'testimonials',
      label: 'Testimonials',
      hint: 'Client reviews and social proof',
      icon: <Star className="w-4 h-4" />,
    },
    {
      key: 'system',
      label: 'System Operations',
      hint: 'Migrations and cron workers',
      icon: <ServerCog className="w-4 h-4" />,
    },
  ], []);

  const activeTabMeta = useMemo(() => tabs.find(tab => tab.key === activeTab), [tabs, activeTab]);

  return (
    <div className="space-y-6 pb-20 font-sans">
      {/* Top Hero Card */}
      <section className="relative overflow-hidden rounded-xl border border-gray-800/80 bg-[#121212] p-6 shadow-2xl">
        <div className="pointer-events-none absolute -right-20 -top-20 h-48 w-48 rounded-full bg-brand-orange/15 blur-3xl" />
        <div className="pointer-events-none absolute -left-16 bottom-0 h-40 w-40 rounded-full bg-orange-300/10 blur-3xl" />
        <div className="relative flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-brand-orange/10 border border-brand-orange/20 rounded-xl">
              <ServerCog className="w-6 h-6 text-brand-orange" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <p className="text-[10px] font-mono font-bold uppercase tracking-[0.2em] text-brand-orange">Admin Controls</p>
              </div>
              <h2 className="text-2xl font-display font-black uppercase tracking-tight text-white">Site &amp; System Settings</h2>
            </div>
          </div>
          <div className="rounded-lg border border-brand-orange/40 bg-brand-orange/10 px-4 py-2 text-right">
            <p className="text-[10px] font-mono uppercase tracking-widest text-gray-400">Current Section</p>
            <p className="text-sm font-mono font-bold text-white">{activeTabMeta?.label ?? 'Company Info'}</p>
          </div>
        </div>
      </section>

      {/* Tab Navigation Grid */}
      <section className="rounded-xl border border-gray-800/80 bg-[#121212] p-3 shadow-xl">
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2">
          {tabs.map(t => {
            const isActive = activeTab === t.key;
            return (
              <button
                key={t.key}
                onClick={() => setActiveTab(t.key)}
                className={[
                  'group rounded-lg border px-3.5 py-3 text-left transition-all cursor-pointer',
                  isActive
                    ? 'border-brand-orange bg-brand-orange/15 text-white shadow-[0_0_15px_rgba(249,115,22,0.15)] font-bold'
                    : 'border-gray-800 bg-brand-darker/60 text-gray-400 hover:border-brand-orange/60 hover:text-white',
                ].join(' ')}
              >
                <span className="flex items-center gap-2 text-xs font-mono font-bold uppercase tracking-wider">
                  {t.icon} {t.label}
                </span>
                <span className="mt-1 block text-[10px] text-gray-500 group-hover:text-gray-300 font-mono truncate">{t.hint}</span>
              </button>
            );
          })}
        </div>
      </section>

      {/* Active Sub-Panel Workspace */}
      <section className="rounded-xl border border-gray-800/80 bg-[#121212] p-6 shadow-2xl">
        {activeTab === 'company' && <CompanyInfoPanel />}
        {activeTab === 'contact' && <ContactPanel />}
        {activeTab === 'footer' && <FooterSettingsPanel />}
        {activeTab === 'team' && <TeamMembersPanel />}
        {activeTab === 'testimonials' && <TestimonialsPanel />}
        {activeTab === 'system' && <SystemPanel />}
      </section>
    </div>
  );
}
