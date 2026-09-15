import { useEffect, useState } from 'react'
import { getMembers } from '../api/members.api'
import { getProjectMembers } from '../api/projectMembers.api'
import { getProjects } from '../api/projects.api'

export default function useCommitteeProjects(user) {
  const [projects, setProjects] = useState([])
  const [projectMembers, setProjectMembers] = useState({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [currentMember, setCurrentMember] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      if (!user?.id) {
        setProjects([])
        setProjectMembers({})
        setCurrentMember(null)
        setLoading(false)
        return
      }

      setLoading(true)
      setError('')

      try {
        const [projectList, members] = await Promise.all([getProjects(), getMembers()])
        const currentMember = (members ?? []).find((member) => member.user?.id === user.id)

        if (!currentMember) {
          if (!cancelled) {
            setError('The logged-in user is not linked to a member profile, so assigned projects cannot be resolved.')
            setProjectMembers({})
            setProjects([])
            setCurrentMember(null)
          }
          return
        }

        const membersEntries = await Promise.all(
          projectList.map(async (project) => {
            try {
              const members = await getProjectMembers(project.id)
              return [project.id, members]
            } catch {
              return [project.id, null]
            }
          }),
        )

        if (cancelled) return

        const membersMap = Object.fromEntries(membersEntries)
        const assignedProjects = projectList.filter((project) =>
          (membersMap[project.id] ?? []).some(
            (member) =>
              member.id === currentMember.id ||
              member.pivot?.member_id === currentMember.id ||
              member.user?.id === user.id,
          ),
        )

        const memberAccessFailures = projectList.length
          ? membersEntries.filter(([, members]) => members === null).length
          : 0

        if (!cancelled) {
          setCurrentMember(currentMember)
          if (memberAccessFailures === projectList.length && projectList.length > 0) {
            setError('Your role can load projects but cannot read project member assignments, so assigned projects cannot be determined.')
          } else if (assignedProjects.length === 0) {
            setError('')
          }
        }

        setProjectMembers(membersMap)
        setProjects(assignedProjects)
      } catch (loadError) {
        if (cancelled) return

        const status = loadError.response?.status
        setError(
          status === 403
            ? 'Your account does not currently have permission to load projects required for the committee dashboard.'
            : loadError.response?.data?.message ?? 'Unable to load assigned committee projects.',
        )
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    load()

    return () => {
      cancelled = true
    }
  }, [user?.id])

  return {
    projects,
    projectMembers,
    loading,
    error,
    currentMember,
  }
}
