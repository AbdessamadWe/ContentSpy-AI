import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../lib/api';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';

export default function PublishingCalendar() {
  const [events, setEvents] = useState([]);
  
  const { data: wpPosts } = useQuery({
    queryKey: ['articles', 'scheduled'],
    queryFn: () => api.get('/articles?publish_status=scheduled').then(r => r.data),
  });
  
  const { data: socialPosts } = useQuery({
    queryKey: ['social-posts', 'scheduled'],
    queryFn: () => api.get('/social/posts?status=scheduled').then(r => r.data),
  });

  useEffect(() => {
    const combined = [];
    
    // WordPress posts
    if (wpPosts?.articles) {
      wpPosts.articles.forEach(article => {
        if (article.scheduled_for) {
          combined.push({
            id: `wp-${article.id}`,
            title: `📝 ${article.title}`,
            start: article.scheduled_for,
            backgroundColor: '#21759b',
            borderColor: '#21759b',
          });
        }
      });
    }
    
    // Social posts
    if (socialPosts?.posts) {
      socialPosts.posts.forEach(post => {
        if (post.scheduled_for) {
          const colors = {
            facebook: '#1877f2',
            instagram: '#e4405f',
            tiktok: '#000000',
            pinterest: '#bd081c',
          };
          combined.push({
            id: `social-${post.id}`,
            title: `📱 ${post.platform}: ${post.caption?.substring(0, 30)}...`,
            start: post.scheduled_for,
            backgroundColor: colors[post.platform] || '#666',
            borderColor: colors[post.platform] || '#666',
          });
        }
      });
    }
    
    setEvents(combined);
  }, [wpPosts, socialPosts]);

  const handleEventDrop = async (info) => {
    const newDate = info.event.start.toISOString();
    const [type, id] = info.event.id.split('-');
    
    try {
      if (type === 'wp') {
        await api.patch(`/articles/${id}`, { scheduled_for: newDate });
      } else {
        await api.patch(`/social/posts/${id}`, { scheduled_for: newDate });
      }
    } catch (error) {
      info.revert();
    }
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Publishing Calendar</h1>
        <div className="flex gap-4">
          <span className="flex items-center gap-2">
            <span className="w-3 h-3 rounded-full bg-[#21759b]"></span>
            WordPress
          </span>
          <span className="flex items-center gap-2">
            <span className="w-3 h-3 rounded-full bg-[#1877f2]"></span>
            Facebook
          </span>
          <span className="flex items-center gap-2">
            <span className="w-3 h-3 rounded-full bg-[#e4405f]"></span>
            Instagram
          </span>
          <span className="flex items-center gap-2">
            <span className="w-3 h-3 rounded-full bg-[#000]"></span>
            TikTok
          </span>
          <span className="flex items-center gap-2">
            <span className="w-3 h-3 rounded-full bg-[#bd081c]"></span>
            Pinterest
          </span>
        </div>
      </div>
      
      <div className="bg-white rounded-lg shadow p-4">
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
          initialView="dayGridMonth"
          events={events}
          editable={true}
          droppable={true}
          eventDrop={handleEventDrop}
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay',
          }}
          height="auto"
        />
      </div>
    </div>
  );
}